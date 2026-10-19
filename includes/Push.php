<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Licenze vendute sul Bookshop: POST /wp-json/raffaello-licenze/v1/eventi.
 *
 * Lo manda il plugin "Raffaello Licenze — Bookshop", firmato con HMAC-SHA256 di
 * timestamp + "." + corpo e il segreto di Config. Eventi: licenze_assegnate,
 * licenze_revocate e ping, che prova collegamento e firma senza scrivere niente.
 * Ogni licenza è idempotente sul suo id esterno (id di License Manager): un
 * push ripetuto non cambia niente, una revoca è definitiva anche se arriva
 * prima dell'assegnazione.
 */
class Push
{
    const ROUTE_NAMESPACE = 'raffaello-licenze/v1';
    const MAX_BODY = 65536;
    const MAX_LICENZE = 50;
    const TOLLERANZA = 300;
    const MAX_SKU = 500;

    /** Ultima richiesta con firma valida: ora UTC, evento, esito, IP. */
    const ULTIMO_EVENTO = 'rcl_ultimo_evento';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        // La firma si verifica in handle(): WordPress richiama il permission_callback anche per
        // l'header Allow (rest_send_allow_header), e ogni rifiuto finirebbe due volte nel log.
        register_rest_route(self::ROUTE_NAMESPACE, '/eventi', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** Firma: 503 senza segreto, 401 se non torna, true se valida. */
    private function verify(\WP_REST_Request $request)
    {
        $secret = Config::secret();
        if ($secret === '') {
            Log::error('Segreto del Bookshop non configurato o non valido (Licenze > Impostazioni): push rifiutato.');
            return new \WP_Error('rcl_non_configurato', 'Servizio non configurato.', ['status' => 503]);
        }

        $ts = (string) $request->get_header('X-Raffaello-Timestamp');
        $signature = (string) $request->get_header('X-Raffaello-Signature');
        if (!preg_match('/^\d{1,12}$/', $ts)) {
            $reason = 'timestamp mancante o non intero';
        } elseif (abs(time() - (int) $ts) > self::TOLLERANZA) {
            $reason = 'timestamp fuori tolleranza di ' . (time() - (int) $ts) . ' s';
        } elseif (!preg_match('/^sha256=([0-9a-f]{64})$/', $signature, $m)) {
            $reason = 'firma mancante o malformata';
        } elseif (!hash_equals(hash_hmac('sha256', $ts . '.' . $request->get_body(), $secret), $m[1])) {
            $reason = 'firma errata';
        } else {
            return true;
        }

        Log::warning('Push rifiutato: ' . $reason . '.', ['ip' => Codes::client_ip()]);
        return new \WP_Error('rcl_firma', 'Firma non valida.', ['status' => 401]);
    }

    public function handle(\WP_REST_Request $request)
    {
        // Prima di tutto la firma.
        $auth = $this->verify($request);
        if ($auth !== true) {
            return $auth;
        }

        $body = $request->get_body();
        $too_big = strlen($body) > self::MAX_BODY;
        $data = $too_big ? null : json_decode($body, true);
        try {
            $response = $too_big ? self::rejected('corpo oltre 64 KB') : self::respond($data);
        } catch (\Throwable $e) {
            Log::error('Push non elaborato (' . get_class($e) . '), il Bookshop riproverà.', ['ordine' => $data['ordine']['id'] ?? null]);
            $response = new \WP_Error('rcl_errore', 'Errore interno, riprovare.', ['status' => 500]);
        }

        // Solo con la firma valida: scrivere il database a ogni rifiuto aprirebbe a un attacco di carico.
        update_option(self::ULTIMO_EVENTO, [
            'ora'    => Database::now(),
            'evento' => is_string($data['evento'] ?? null) ? substr($data['evento'], 0, 40) : '',
            'esito'  => is_wp_error($response) ? $response->get_error_code() : 'ok',
            'ip'     => Codes::client_ip(),
        ], false);
        return $response;
    }

    /** Corpo di una richiesta con firma valida: ping, oppure licenze assegnate o revocate. */
    private static function respond($data)
    {
        if (($data['evento'] ?? null) === 'ping') {
            $skus = self::parse_skus($data);
            return is_string($skus) ? self::rejected($skus) : self::ping($skus);
        }
        $evento = self::parse($data);
        if (is_string($evento)) {
            return self::rejected($evento);
        }

        // Un evento per ordine alla volta: assegnazione e revoca dello stesso ordine, e la
        // scelta del primo codice per ordine e licenza, non si sovrappongono.
        $lock = 'rcl_ordine_' . $evento['ordine_id'];
        if (!Database::lock($lock, 5)) {
            Log::warning('Push rimandato: ordine già in elaborazione, il Bookshop riproverà.', ['ordine' => $evento['ordine_id']]);
            return new \WP_Error('rcl_occupato', 'Ordine in elaborazione, riprovare.', ['status' => 503]);
        }
        $esiti = [];
        try {
            foreach ($evento['licenze'] as $licenza) {
                $esiti[] = [
                    'id'    => $licenza['id'],
                    'esito' => $evento['evento'] === 'licenze_revocate' ? self::revoke($licenza, $evento) : self::assign($licenza, $evento),
                ];
            }
        } finally {
            Database::unlock($lock);
        }

        $sintesi = [];
        foreach ($evento['licenze'] as $i => $licenza) {
            $sintesi[] = $licenza['id'] . ' ' . Codes::mask($licenza['chiave']) . ' ' . $esiti[$i]['esito'];
        }
        Log::info('Push ' . $evento['evento'] . ' elaborato.', [
            'ordine' => $evento['ordine_id'],
            'numero' => $evento['numero'],
            'sub'    => $evento['sub'],
            'email'  => Log::email($evento['email']),
            'esiti'  => $sintesi,
        ]);

        return new \WP_REST_Response(['ok' => true, 'esiti' => $esiti], 200);
    }

    private static function rejected(string $reason): \WP_Error
    {
        Log::warning('Push rifiutato: ' . $reason . '.');
        return new \WP_Error('rcl_formato', $reason, ['status' => 400]);
    }

    /** Ping: versione, ora e gli SKU che non corrispondono a una licenza attiva. Non scrive niente. */
    private static function ping(array $skus): \WP_REST_Response
    {
        global $wpdb;
        // In minuscolo: la colonna sku non distingue le maiuscole, come in Licenses::by_sku().
        $known = $skus ? array_map('strtolower', Database::get_col($wpdb->prepare(
            'SELECT sku FROM ' . Database::table('licenze') . ' WHERE attiva = 1 AND sku IN (' . implode(', ', array_fill(0, count($skus), '%s')) . ')',
            $skus
        ))) : [];
        $unknown = array_values(array_filter($skus, function ($sku) use ($known) {
            return !in_array(strtolower($sku), $known, true);
        }));
        Log::info('Ping dal Bookshop.', ['sku' => count($skus), 'sku_sconosciuti' => $unknown]);

        return new \WP_REST_Response([
            'ok'              => true,
            'versione'        => RCL_VERSION,
            'ora'             => gmdate('Y-m-d\TH:i:s\Z', time()),
            'sku_sconosciuti' => $unknown,
        ], 200);
    }

    /** SKU del ping, facoltativi: al massimo 500 stringhe. L'elenco senza doppioni, o il motivo del rifiuto. */
    private static function parse_skus(array $data)
    {
        $skus = $data['skus'] ?? [];
        if (!is_array($skus) || count($skus) > self::MAX_SKU) {
            return 'skus: elenco di al massimo ' . self::MAX_SKU;
        }
        $out = [];
        foreach (array_values($skus) as $i => $sku) {
            if (!is_string($sku) || trim($sku) === '' || strlen(trim($sku)) > 100) {
                return "skus[$i] non valido";
            }
            $out[] = trim($sku);
        }
        return array_values(array_unique($out));
    }

    /** Valida il corpo e lo normalizza; un rifiuto restituisce il motivo. */
    private static function parse($data)
    {
        if (!is_array($data)) {
            return 'corpo non JSON';
        }
        if (!in_array($data['evento'] ?? null, ['licenze_assegnate', 'licenze_revocate'], true)) {
            return 'evento sconosciuto';
        }
        $ordine_id = $data['ordine']['id'] ?? null;
        if (!is_int($ordine_id) || $ordine_id <= 0) {
            return 'ordine.id non valido';
        }
        $sub = $data['cliente']['sub'] ?? null;
        if (is_int($sub)) {
            $sub = (string) $sub;
        }
        if ($sub !== null && (!is_string($sub) || !preg_match('/^[\x21-\x7e]{1,64}$/', $sub))) {
            return 'cliente.sub non valido';
        }
        $licenze = $data['licenze'] ?? null;
        if (!is_array($licenze) || !$licenze || count($licenze) > self::MAX_LICENZE || array_keys($licenze) !== range(0, count($licenze) - 1)) {
            return 'licenze: da 1 a ' . self::MAX_LICENZE;
        }

        $out = [];
        foreach ($licenze as $i => $l) {
            $id = $l['id'] ?? null;
            if (!is_int($id) || $id <= 0) {
                return "licenze[$i].id non valido";
            }
            $chiave = is_string($l['chiave'] ?? null) ? Database::normalize_code($l['chiave']) : '';
            if (!preg_match('/^[A-Z0-9]{1,64}$/', $chiave)) {
                return "licenze[$i].chiave non valida";
            }
            $riga = $l['riga_ordine'] ?? null;
            if (!is_int($riga) || $riga < 0) {
                return "licenze[$i].riga_ordine non valida";
            }
            $sku = $l['prodotto']['sku'] ?? null;
            if ($sku !== null && (!is_string($sku) || strlen(trim($sku)) > 100)) {
                return "licenze[$i].prodotto.sku non valido";
            }
            $scade_il = $l['scade_il'] ?? null;
            if ($scade_il !== null) {
                $date = is_string($scade_il) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $scade_il)
                    ? date_create_immutable($scade_il)
                    : false;
                if (!$date) {
                    return "licenze[$i].scade_il non valido";
                }
                $scade_il = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            $out[] = [
                'id'          => $id,
                'chiave'      => $chiave,
                'riga_ordine' => $riga,
                'sku'         => $sku !== null && trim($sku) !== '' ? trim($sku) : null,
                'scade_il'    => $scade_il,
            ];
        }

        return [
            'evento'    => $data['evento'],
            'ordine_id' => $ordine_id,
            'numero'    => is_scalar($data['ordine']['numero'] ?? null) ? (string) $data['ordine']['numero'] : '',
            'sub'       => $sub,
            'email'     => is_string($data['cliente']['email'] ?? null) ? $data['cliente']['email'] : '',
            'licenze'   => $out,
        ];
    }

    /** licenze_assegnate: salva il codice, lo collega alla licenza dallo SKU e attiva per l'acquirente. */
    private static function assign(array $licenza, array $evento): string
    {
        $code = self::upsert($licenza, $evento, null);
        if (!$code) {
            return 'conflitto';
        }
        if ($code->revocato_il !== null) {
            return 'revocata';
        }
        if ($code->licenza_id === null) {
            $catalogo = $code->sku !== null ? Licenses::by_sku($code->sku) : null;
            if (!$catalogo) {
                return 'in_attesa_configurazione';
            }
            Database::update('codici', ['licenza_id' => (int) $catalogo->id], ['id' => (int) $code->id, 'licenza_id' => null]);
            $code = Database::get_code((int) $code->id);
        }
        return Activations::assign_purchase($code);
    }

    /** licenze_revocate: revoca definitiva; se il codice non c'è ancora lo crea già revocato (lapide). */
    private static function revoke(array $licenza, array $evento): string
    {
        $code = self::upsert($licenza, $evento, Database::now());
        if (!$code) {
            return 'conflitto';
        }
        Database::update('codici', ['revocato_il' => Database::now()], ['id' => (int) $code->id, 'revocato_il' => null]);
        Activations::revoke_code((int) $code->id);
        return 'revocata';
    }

    /**
     * Codice acquistato con quell'id esterno, creato se manca. Null se esiste
     * con chiave, ordine o destinatario diversi, o se la chiave è già di un
     * altro codice: è un conflitto e non si tocca niente.
     */
    private static function upsert(array $licenza, array $evento, ?string $revocato_il)
    {
        global $wpdb;
        $find = function () use ($wpdb, $licenza) {
            return Database::get_row($wpdb->prepare('SELECT * FROM ' . Database::table('codici') . ' WHERE id_esterno = %d', $licenza['id']));
        };

        $code = $find();
        if (!$code) {
            Database::insert_ignore('codici', [
                'codice'           => $licenza['chiave'],
                'tipo'             => Codes::ACQUISTATO,
                'id_esterno'       => $licenza['id'],
                'ordine_id'        => $evento['ordine_id'],
                'riga_ordine'      => $licenza['riga_ordine'],
                'sku'              => $licenza['sku'],
                'destinatario_sub' => $evento['sub'],
                'scade_il'         => $licenza['scade_il'],
                'revocato_il'      => $revocato_il,
                'data_creazione'   => Database::now(),
            ]);
            $code = $find();
        }

        if (!$code || $code->codice !== $licenza['chiave'] || (int) $code->ordine_id !== $evento['ordine_id'] || $code->destinatario_sub !== $evento['sub']) {
            Log::warning('Licenza in conflitto con i dati già ricevuti: nessuna modifica.', [
                'id_esterno' => $licenza['id'],
                'codice'     => Codes::mask($licenza['chiave']),
                'ordine'     => $evento['ordine_id'],
                'sub'        => $evento['sub'],
            ]);
            return null;
        }
        return $code;
    }
}
