<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Riscatto manuale dei codici e verifica dell'accesso.
 *
 * Un codice stampato è condiviso tra tutte le copie del libro: ogni utente che
 * lo riscatta riceve la sua attivazione. Un codice acquistato sul Bookshop è
 * monouso. Lo stesso servizio serve tutti i form (shortcode, form contestuale,
 * blocco di pagina, "I miei contenuti"), con AJAX e senza JavaScript.
 */
class Codes
{
    const STAMPATO = 'stampato';
    const ACQUISTATO = 'acquistato';

    /** Prefisso dei codici di Raffaello Scuola nel generatore di License Manager. */
    const PREFISSO = 'RSCU';

    /** Alfabeto dei generatori di License Manager. */
    const ALFABETO = 'ABCDEFGHLMNPQRSTUVZ123456789';

    /** Lock dei tentativi, per utente. */
    const TENTATIVI = 'rcl_tentativi_';

    /** User meta dei tentativi: ['n' => quanti, 'dal' => inizio dell'ora]. */
    const META_TENTATIVI = 'rcl_tentativi';

    /**
     * Riscatta un codice per l'utente indicato.
     *
     * @param string   $codice_raw Codice grezzo inserito dall'utente.
     * @param int      $user_id    Utente autenticato.
     * @param int|null $post_id    Contenuto dal quale è partito il riscatto.
     *
     * @return array { success, code, message, post_ids, links, unlocks_current }
     */
    public static function redeem(string $codice_raw, int $user_id, ?int $post_id = null): array
    {
        $norm = Database::normalize_code($codice_raw);
        if ($norm === '') {
            return self::result(false, 'vuoto', __('Inserisci un codice.', 'raffaello-codici-libro'));
        }

        // Controllo e conteggio dei tentativi con il lock dell'utente: richieste parallele non lo aggirano.
        if (!Database::lock(self::TENTATIVI . $user_id, 5)) {
            return self::busy();
        }
        try {
            $wait = self::wait_minutes($user_id);
            $code = $wait > 0 ? null : Database::find_code($norm);
            if ($wait === 0 && !$code) {
                self::count_attempt($user_id);
            }
        } finally {
            Database::unlock(self::TENTATIVI . $user_id);
        }
        if ($wait > 0) {
            return self::too_many($wait);
        }
        if (!$code) {
            return self::not_found($norm);
        }

        $now = Database::now();
        $licenza = $code->licenza_id !== null ? Licenses::get((int) $code->licenza_id) : null;

        // 1. L'utente ha già un'attivazione da questo codice: conta il suo diritto.
        $own = self::own_activation($code, $user_id);
        if ($own) {
            $own_licenza = Licenses::get((int) $own->licenza_id);
            if ($own->stato === Activations::REVOCATA || $code->revocato_il !== null) {
                return self::result(false, 'accesso_revocato', __('Il tuo accesso con questo codice è stato revocato. Per informazioni contatta l\'assistenza.', 'raffaello-codici-libro'));
            }
            if ($own->scade_il !== null && $own->scade_il <= $now) {
                /* translators: %s: data */
                return self::result(false, 'accesso_scaduto', sprintf(__('Il tuo accesso con questo codice è scaduto il %s.', 'raffaello-codici-libro'), self::date($own->scade_il)));
            }
            if (!(int) $own_licenza->attiva) {
                return self::result(false, 'non_attivo', __('Questo codice non è più attivo.', 'raffaello-codici-libro'));
            }
            // Ripetere il riscatto rifà anche una sincronizzazione dei corsi non riuscita.
            LearnDash::sync($user_id);
            return self::result(true, 'gia_attivo', __('Questo codice è già attivo sul tuo profilo.', 'raffaello-codici-libro'), $own_licenza);
        }

        // 2. Codice revocato o disattivato, licenza disattivata, termine per riscattare superato.
        // Da qui in poi gli esiti negativi contano come tentativi, come i codici inesistenti.
        if ($code->revocato_il !== null || !(int) $code->attivo || ($licenza && !(int) $licenza->attiva)) {
            return self::counted($user_id, self::result(false, 'non_attivo', __('Questo codice non è più attivo.', 'raffaello-codici-libro')));
        }
        if (($code->attivabile_fino !== null && $code->attivabile_fino <= $now)
            || ($code->tipo === self::ACQUISTATO && $code->scade_il !== null && $code->scade_il <= $now)) {
            return self::counted($user_id, self::result(false, 'scaduto', __('Questo codice è scaduto.', 'raffaello-codici-libro')));
        }

        // 3. Acquistato e già attivato da un altro, anche se in attesa di agganciarsi al suo profilo.
        if ($code->tipo === self::ACQUISTATO && Activations::by_key('acq:' . $code->id)) {
            return self::counted($user_id, self::result(false, 'gia_utilizzato', __('Questo codice è già stato utilizzato.', 'raffaello-codici-libro')));
        }

        // 4. Acquistato con uno SKU non ancora configurato.
        if ($code->tipo === self::ACQUISTATO && !$licenza) {
            Log::warning('Riscatto di un codice acquistato in attesa di configurazione.', ['codice' => self::mask($code->codice), 'sku' => $code->sku, 'user_id' => $user_id]);
            return self::result(false, 'in_attesa', __('Codice ricevuto: l\'attivazione sarà disponibile a breve.', 'raffaello-codici-libro'));
        }

        // 5. Licenza senza contenuti.
        if (!$licenza || !Licenses::posts((int) $licenza->id)) {
            return self::result(false, 'senza_contenuti', __('Codice valido ma non collegato ad alcun contenuto. Contatta l\'assistenza.', 'raffaello-codici-libro'));
        }

        // 6. Valido.
        $acquistato = $code->tipo === self::ACQUISTATO;
        $durata = $licenza->durata_giorni !== null ? (int) $licenza->durata_giorni : 0;
        list($row, $created) = Activations::create([
            'chiave'     => $acquistato ? 'acq:' . $code->id : 'st:' . $code->id . ':' . $user_id,
            'licenza_id' => (int) $licenza->id,
            'user_id'    => $user_id,
            'sub'        => Activations::sub_of($user_id),
            'codice_id'  => (int) $code->id,
            'origine'    => $acquistato ? Activations::ACQUISTO : Activations::STAMPATO,
            'ordine_id'  => $acquistato ? (int) $code->ordine_id : null,
            'scade_il'   => $acquistato ? $code->scade_il : ($durata > 0 ? gmdate('Y-m-d H:i:s', time() + $durata * DAY_IN_SECONDS) : null),
            'ip'         => self::client_ip(),
        ]);
        if (!$created) {
            // Una richiesta concorrente è arrivata prima: un doppio invio dello stesso utente o un altro utente.
            return (int) $row->user_id === $user_id
                ? self::result(true, 'gia_attivo', __('Questo codice è già attivo sul tuo profilo.', 'raffaello-codici-libro'), $licenza)
                : self::counted($user_id, self::result(false, 'gia_utilizzato', __('Questo codice è già stato utilizzato.', 'raffaello-codici-libro')));
        }

        LearnDash::sync($user_id);
        Log::info('Codice riscattato.', ['codice' => self::mask($code->codice), 'tipo' => $code->tipo, 'licenza' => (int) $licenza->id, 'user_id' => $user_id]);

        /* translators: %s: nome della licenza */
        $result = self::result(true, 'attivata', sprintf(__('Codice attivato: %s.', 'raffaello-codici-libro'), $licenza->nome), $licenza);
        $result['unlocks_current'] = $post_id !== null
            && (in_array($post_id, $result['post_ids'], true) || $post_id === (int) get_option(Frontend::OPTION_MIEI_CONTENUTI));
        return $result;
    }

    /**
     * Attivazione dell'utente da questo codice. Per un acquistato in attesa con
     * il sub dell'utente (per esempio arrivato prima del suo primo login) prova
     * prima ad agganciarla.
     */
    private static function own_activation($code, int $user_id)
    {
        if ($code->tipo !== self::ACQUISTATO) {
            return Activations::by_key('st:' . $code->id . ':' . $user_id);
        }
        $row = Activations::by_key('acq:' . $code->id);
        if ($row && $row->user_id === null && $row->sub === Activations::sub_of($user_id) && Activations::link_user($user_id)) {
            LearnDash::sync($user_id);
            $row = Activations::by_key('acq:' . $code->id);
        }
        return $row && (int) $row->user_id === $user_id ? $row : null;
    }

    /** Codice non trovato: forse è di un'altra piattaforma, altrimenti non è valido. */
    private static function not_found(string $norm): array
    {
        if (preg_match('/^([A-Z]{4})[' . self::ALFABETO . ']{8}$/', $norm, $m) && $m[1] !== self::PREFISSO) {
            // Prefisso → piattaforma, "*" per gli altri prefissi.
            $piattaforme = apply_filters('rcl_piattaforme_codici', [
                'RLAB' => __('RAF Lab', 'raffaello-codici-libro'),
                '*'    => __('un libro digitale di Raffaello Digitale', 'raffaello-codici-libro'),
            ]);
            $dove = $piattaforme[$m[1]] ?? $piattaforme['*'];
            /* translators: %s: piattaforma */
            return self::result(false, 'non_pertinente', sprintf(__('Questo formato di codice è destinato a %s: va attivato su quella piattaforma, non qui.', 'raffaello-codici-libro'), $dove));
        }
        return self::result(false, 'non_valido', __('Codice non valido.', 'raffaello-codici-libro'));
    }

    /** Verifica se l'utente ha accesso a un contenuto. */
    public static function user_can_access(int $user_id, int $post_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }
        // Gli amministratori vedono sempre i contenuti (anteprima editoriale).
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        return Database::user_has_access($user_id, $post_id);
    }

    /** Codice da mostrare nei log e nell'admin: RSCU-****-AB12, oppure solo le ultime due cifre. */
    public static function mask(string $codice): string
    {
        if (strlen($codice) === 12) {
            return substr($codice, 0, 4) . '-****-' . substr($codice, 8);
        }
        return str_repeat('*', max(0, strlen($codice) - 2)) . substr($codice, -2);
    }

    /** Data locale (gg/mm/aaaa) da una data UTC del database. */
    public static function date(string $utc): string
    {
        return get_date_from_gmt($utc, 'd/m/Y');
    }

    /* ----------------------------------------------------------------------
     * Limite di tentativi, per utente e per ora: contano i codici non trovati (non
     * validi o di un'altra piattaforma) e quelli che chi li prova non può usare
     * (non attivi, scaduti, già usati da altri). Non contano gli esiti sul proprio
     * codice: già attivo, accesso scaduto o revocato.
     * -------------------------------------------------------------------- */

    /**
     * Tentativi dell'ora in corso, dal database: si leggono sotto il lock, e la cache della richiesta
     * può averli di prima, quando un'altra richiesta dello stesso utente li ha già contati.
     */
    private static function attempts(int $user_id): array
    {
        wp_cache_delete($user_id, 'user_meta');
        $t = get_user_meta($user_id, self::META_TENTATIVI, true);
        return is_array($t) && $t['dal'] + HOUR_IN_SECONDS > time() ? $t : ['n' => 0, 'dal' => time()];
    }

    private static function wait_minutes(int $user_id): int
    {
        $t = self::attempts($user_id);
        if ($t['n'] < (int) apply_filters('rcl_tentativi_massimi', 10)) {
            return 0;
        }
        return max(1, (int) ceil(($t['dal'] + HOUR_IN_SECONDS - time()) / MINUTE_IN_SECONDS));
    }

    /**
     * Esito che conta come tentativo. Con il lock dell'utente si ricontrolla la soglia, che richieste
     * parallele possono aver passato insieme, e si conta prima di dare l'esito.
     */
    private static function counted(int $user_id, array $result): array
    {
        if (!Database::lock(self::TENTATIVI . $user_id, 5)) {
            return self::busy();
        }
        try {
            $wait = self::wait_minutes($user_id);
            if ($wait === 0) {
                self::count_attempt($user_id);
            }
        } finally {
            Database::unlock(self::TENTATIVI . $user_id);
        }
        return $wait > 0 ? self::too_many($wait) : $result;
    }

    /** Lock dei tentativi non arrivato: un errore, mai un esito senza conteggio. */
    private static function busy(): array
    {
        return self::result(false, 'errore', __('Si è verificato un errore. Riprova fra poco.', 'raffaello-codici-libro'));
    }

    private static function too_many(int $wait): array
    {
        /* translators: %d: minuti */
        return self::result(false, 'troppi_tentativi', sprintf(_n('Troppi tentativi, riprova fra %d minuto.', 'Troppi tentativi, riprova fra %d minuti.', $wait, 'raffaello-codici-libro'), $wait));
    }

    private static function count_attempt(int $user_id): void
    {
        $t = self::attempts($user_id);
        $t['n']++;
        update_user_meta($user_id, self::META_TENTATIVI, $t);
    }

    /** Struttura di risposta; con la licenza aggiunge i suoi contenuti e i link. */
    private static function result(bool $success, string $code, string $message, $licenza = null): array
    {
        $post_ids = $licenza ? Licenses::posts((int) $licenza->id) : [];
        $links = [];
        foreach ($post_ids as $id) {
            if (get_post_status($id) === 'publish') {
                $links[] = ['url' => get_permalink($id), 'title' => get_the_title($id)];
            }
        }
        return [
            'success'         => $success,
            'code'            => $code,
            'message'         => $message,
            'post_ids'        => $post_ids,
            'links'           => $links,
            'unlocks_current' => false,
        ];
    }

    /**
     * IP del client, solo per il registro delle attivazioni. Dietro Cloudflare
     * è in CF-Connecting-IP; X-Forwarded-For non si legge perché lo scrive il client.
     */
    public static function client_ip(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $key) {
            $ip = isset($_SERVER[$key]) ? filter_var(wp_unslash($_SERVER[$key]), FILTER_VALIDATE_IP) : false;
            if ($ip) {
                return $ip;
            }
        }
        return null;
    }
}
