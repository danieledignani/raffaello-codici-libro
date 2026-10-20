<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Attivazioni: chi ha accesso a quale licenza. La colonna chiave (UNIQUE) le
 * rende atomiche senza transazioni né contatori:
 * - acq:<codice_id>           una sola per codice acquistato (monouso);
 * - st:<codice_id>:<user_id>  una per utente e codice stampato;
 * - man:<licenza_id>:<user_id> una per utente e licenza data dall'admin.
 *
 * Gli acquisti arrivano con il sub di Identity e si agganciano all'utente
 * WordPress quando ce n'è uno solo con quel sub (subito o al login).
 */
class Activations
{
    const ATTIVA = 'attiva';
    const REVOCATA = 'revocata';

    const STAMPATO = 'stampato';
    const ACQUISTO = 'acquisto';
    const MANUALE = 'manuale';

    public function register(): void
    {
        add_action('ri_user_logged_in', [$this, 'on_login']);
    }

    /**
     * Login con Raffaello Identity: aggancia gli acquisti in attesa e riallinea
     * LearnDash. Un errore finisce nel log e non blocca l'accesso.
     */
    public function on_login($user): void
    {
        try {
            self::link_user((int) $user->ID);
            LearnDash::sync((int) $user->ID);
        } catch (\Throwable $e) {
            Log::error('Aggancio al login non riuscito (' . get_class($e) . ').', ['user_id' => (int) $user->ID]);
        }
    }

    /** sub di Identity dell'utente; null per gli account WordPress locali. */
    public static function sub_of(int $user_id): ?string
    {
        $sub = (string) get_user_meta($user_id, 'ri_oidc_sub', true);
        return $sub !== '' ? $sub : null;
    }

    /** Utenti WordPress con quel sub (ne legge al massimo due: basta per il conflitto). */
    public static function users_by_sub(string $sub): array
    {
        global $wpdb;
        return array_map('intval', Database::get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ri_oidc_sub' AND meta_value = %s LIMIT 2",
            $sub
        )));
    }

    /**
     * Aggancia all'utente le attivazioni in attesa (user_id NULL) del suo sub.
     * Con più utenti sullo stesso sub non aggancia niente. Nessun trasferimento
     * fra utenti: le attivazioni già agganciate non si spostano.
     */
    public static function link_user(int $user_id): int
    {
        global $wpdb;
        $sub = self::sub_of($user_id);
        if ($sub === null) {
            return 0;
        }
        if (count(self::users_by_sub($sub)) > 1) {
            Log::warning('Più utenti WordPress con lo stesso sub: attivazioni in attesa non agganciate.', ['sub' => $sub, 'user_id' => $user_id]);
            return 0;
        }
        $n = Database::query($wpdb->prepare(
            'UPDATE ' . Database::table('attivazioni') . ' SET user_id = %d, data_modifica = %s WHERE user_id IS NULL AND sub = %s',
            $user_id,
            Database::now(),
            $sub
        ));
        if ($n) {
            Log::info('Attivazioni in attesa agganciate all\'utente.', ['user_id' => $user_id, 'sub' => $sub, 'attivazioni' => $n]);
        }
        return $n;
    }

    public static function get(int $id)
    {
        global $wpdb;
        return Database::get_row($wpdb->prepare('SELECT * FROM ' . Database::table('attivazioni') . ' WHERE id = %d', $id));
    }

    public static function by_key(string $chiave)
    {
        global $wpdb;
        return Database::get_row($wpdb->prepare('SELECT * FROM ' . Database::table('attivazioni') . ' WHERE chiave = %s', $chiave));
    }

    /**
     * INSERT IGNORE e lettura della riga con quella chiave. Restituisce
     * [riga, creata]: se l'indice ha rifiutato l'inserimento la riga è quella
     * che esisteva già, anche se l'ha appena scritta una richiesta concorrente.
     */
    public static function create(array $data): array
    {
        $now = Database::now();
        $created = Database::insert_ignore('attivazioni', $data + [
            'stato'          => self::ATTIVA,
            'data_creazione' => $now,
            'data_modifica'  => $now,
        ]) === 1;
        return [self::by_key($data['chiave']), $created];
    }

    /**
     * Attivazione per l'acquirente di un codice acquistato già collegato a una
     * licenza (push dal Bookshop e configurazione tardiva dello SKU), con il lock
     * dell'ordine già preso. Restituisce l'esito per il Bookshop.
     */
    public static function assign_purchase($code): string
    {
        global $wpdb;
        $sub = $code->destinatario_sub;

        // Se il codice ha già la sua attivazione (un reinvio, o un riscatto a mano) l'esito viene da lei:
        // così un ordine senza destinatario riscattato a mano si chiude, e la seconda copia riscattata
        // dall'acquirente non rende "libera" la prima.
        $row = self::by_key('acq:' . $code->id);
        if (!$row) {
            if ($sub === null) {
                return 'senza_destinatario';
            }
            // Quantità > 1: per ordine e licenza l'acquirente riceve solo il primo codice, gli altri restano liberi.
            $first = Database::get_var($wpdb->prepare(
                'SELECT 1 FROM ' . Database::table('attivazioni') . ' WHERE ordine_id = %d AND licenza_id = %d AND sub = %s AND codice_id <> %d LIMIT 1',
                (int) $code->ordine_id,
                (int) $code->licenza_id,
                $sub,
                (int) $code->id
            ));
            if ($first) {
                return 'codice_libero';
            }
            $users = self::users_by_sub($sub);
            $row = self::create([
                'chiave'     => 'acq:' . $code->id,
                'licenza_id' => (int) $code->licenza_id,
                'user_id'    => count($users) === 1 ? $users[0] : null,
                'sub'        => $sub,
                'codice_id'  => (int) $code->id,
                'origine'    => self::ACQUISTO,
                'ordine_id'  => (int) $code->ordine_id,
                'scade_il'   => $code->scade_il,
            ])[0];
        }

        // Riscattato a mano da qualcun altro, quando era libero.
        if ($sub !== null && $row->sub !== $sub) {
            return 'codice_libero';
        }
        // Ripetizione dopo che l'utente è comparso: si aggancia ora.
        if ($row->user_id === null && $sub !== null) {
            $users = self::users_by_sub($sub);
            if (count($users) === 1) {
                Database::update('attivazioni', ['user_id' => $users[0], 'data_modifica' => Database::now()], ['id' => (int) $row->id, 'user_id' => null]);
                $row = self::by_key('acq:' . $code->id);
            }
        }
        // Sempre, anche se l'attivazione c'era già: un reinvio rifà una sincronizzazione fallita.
        if ($row->user_id !== null) {
            LearnDash::sync((int) $row->user_id);
        }
        return $row->stato === self::REVOCATA ? 'revocata' : 'attivata';
    }

    /**
     * Revoca le attivazioni di un codice (rimborso o annullamento sul Bookshop).
     * Sincronizza gli utenti di tutte le attivazioni del codice, anche di quelle
     * già revocate: un reinvio della revoca rifà una sincronizzazione fallita.
     */
    public static function revoke_code(int $codice_id): void
    {
        global $wpdb;
        $table = Database::table('attivazioni');
        Database::query($wpdb->prepare("UPDATE {$table} SET stato = %s, data_modifica = %s WHERE codice_id = %d AND stato = %s", self::REVOCATA, Database::now(), $codice_id, self::ATTIVA));
        LearnDash::sync_users(Database::get_col($wpdb->prepare("SELECT DISTINCT user_id FROM {$table} WHERE codice_id = %d AND user_id IS NOT NULL", $codice_id)));
    }

    /**
     * Revoca di una singola attivazione dall'admin, anche per i rimborsi parziali. Vero se l'ha
     * revocata ora. Sincronizza anche se era già revocata: ripeterla rifà una sincronizzazione fallita.
     */
    public static function revoke(int $id): bool
    {
        $row = self::get($id);
        if (!$row) {
            return false;
        }
        $changed = Database::update('attivazioni', ['stato' => self::REVOCATA, 'data_modifica' => Database::now()], ['id' => $id, 'stato' => self::ATTIVA]) === 1;
        if ($row->user_id !== null) {
            LearnDash::sync((int) $row->user_id);
        }
        return $changed;
    }

    /**
     * Riattivazione esplicita dall'admin. Non vale per le attivazioni di un
     * codice revocato definitivamente. Vero se l'ha riattivata ora; sincronizza
     * anche se era già attiva: ripeterla rifà una sincronizzazione fallita.
     */
    public static function reactivate(int $id): bool
    {
        $row = self::get($id);
        if (!$row) {
            return false;
        }
        $code = $row->codice_id !== null ? Database::get_code((int) $row->codice_id) : null;
        if ($code && $code->revocato_il !== null) {
            return false;
        }
        $changed = Database::update('attivazioni', ['stato' => self::ATTIVA, 'data_modifica' => Database::now()], ['id' => $id, 'stato' => self::REVOCATA]) === 1;
        if ($row->user_id !== null) {
            LearnDash::sync((int) $row->user_id);
        }
        return $changed;
    }

    /**
     * Attivazione manuale dall'admin, una per licenza e utente: ripetere il form
     * non la duplica e non riattiva una revocata. Esiti: creata, esistente, revocata.
     */
    public static function grant(int $licenza_id, int $user_id, ?string $scade_il): string
    {
        list($row, $created) = self::create([
            'chiave'     => 'man:' . $licenza_id . ':' . $user_id,
            'licenza_id' => $licenza_id,
            'user_id'    => $user_id,
            'sub'        => self::sub_of($user_id),
            'origine'    => self::MANUALE,
            'scade_il'   => $scade_il,
        ]);
        // Anche se c'era già: ripetere il form rifà una sincronizzazione fallita.
        LearnDash::sync($user_id);
        if ($created) {
            return 'creata';
        }
        return $row->stato === self::REVOCATA ? 'revocata' : 'esistente';
    }

    /** Attivazioni non revocate dell'utente (né lui né il codice), con nome e stato della licenza. */
    public static function of_user(int $user_id): array
    {
        global $wpdb;
        return Database::get_results($wpdb->prepare(
            'SELECT a.licenza_id, a.scade_il, l.nome, l.attiva
             FROM ' . Database::table('attivazioni') . ' a
             JOIN ' . Database::table('licenze') . ' l ON l.id = a.licenza_id
             LEFT JOIN ' . Database::table('codici') . ' c ON c.id = a.codice_id
             WHERE a.user_id = %d AND a.stato = %s AND (a.codice_id IS NULL OR c.revocato_il IS NULL)
             ORDER BY l.nome',
            $user_id,
            self::ATTIVA
        ));
    }
}
