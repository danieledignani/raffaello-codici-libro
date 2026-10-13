<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Catalogo delle licenze e contenuti che sbloccano. rcl_licenza_post è l'unica
 * fonte delle associazioni: la usano anche il riquadro della pagina e l'admin.
 */
class Licenses
{
    public static function get(int $id)
    {
        global $wpdb;
        return Database::get_row($wpdb->prepare('SELECT * FROM ' . Database::table('licenze') . ' WHERE id = %d', $id));
    }

    public static function by_sku(string $sku)
    {
        global $wpdb;
        return Database::get_row($wpdb->prepare('SELECT * FROM ' . Database::table('licenze') . ' WHERE sku = %s', $sku));
    }

    /** Tutte le licenze, per nome. */
    public static function all(): array
    {
        return Database::get_results('SELECT * FROM ' . Database::table('licenze') . ' ORDER BY nome, id');
    }

    /** Tipi di contenuto che una licenza può sbloccare. */
    public static function post_types(): array
    {
        return apply_filters('rcl_licenza_post_types', array_values(array_filter(['page', 'post', 'sfwd-courses'], 'post_type_exists')));
    }

    /** Id dei contenuti di una licenza. */
    public static function posts(int $licenza_id): array
    {
        global $wpdb;
        return array_map('intval', Database::get_col($wpdb->prepare('SELECT post_id FROM ' . Database::table('licenza_post') . ' WHERE licenza_id = %d ORDER BY post_id', $licenza_id)));
    }

    /** Id delle licenze che sbloccano un contenuto. */
    public static function of_post(int $post_id): array
    {
        global $wpdb;
        return array_map('intval', Database::get_col($wpdb->prepare('SELECT licenza_id FROM ' . Database::table('licenza_post') . ' WHERE post_id = %d ORDER BY licenza_id', $post_id)));
    }

    /** Utenti con attivazioni della licenza. */
    public static function users(int $licenza_id): array
    {
        global $wpdb;
        return array_map('intval', Database::get_col($wpdb->prepare('SELECT DISTINCT user_id FROM ' . Database::table('attivazioni') . ' WHERE licenza_id = %d AND user_id IS NOT NULL', $licenza_id)));
    }

    /**
     * Crea o aggiorna una licenza e i suoi contenuti. Restituisce l'id oppure
     * WP_Error se lo SKU è di un'altra licenza. Con uno SKU collega i codici
     * acquistati arrivati prima della configurazione.
     */
    public static function save(int $id, string $nome, ?string $sku, ?int $durata_giorni, bool $attiva, array $post_ids)
    {
        $sku = $sku !== null && trim($sku) !== '' ? trim($sku) : null;
        $other = $sku !== null ? self::by_sku($sku) : null;
        if ($other && (int) $other->id !== $id) {
            return new \WP_Error('sku_duplicato', __('Lo SKU è già usato da un\'altra licenza.', 'raffaello-codici-libro'));
        }

        $data = [
            'nome'          => $nome,
            'sku'           => $sku,
            'durata_giorni' => $durata_giorni,
            'attiva'        => $attiva ? 1 : 0,
            'data_modifica' => Database::now(),
        ];
        $before = $id > 0 ? self::get($id) : null;
        if ($before) {
            Database::update('licenze', $data, ['id' => $id]);
        } else {
            $id = Database::insert('licenze', $data + ['data_creazione' => $data['data_modifica']]);
        }

        $changed = self::replace_posts($id, $post_ids);

        // Licenza sospesa o riattivata, corsi aggiunti o tolti: si riallineano i suoi utenti.
        if ($before && ((int) $before->attiva !== $data['attiva'] || self::has_courses($changed))) {
            LearnDash::sync_users(self::users($id));
        }

        if ($sku !== null) {
            self::configure_sku(self::get($id));
        }

        return $id;
    }

    /** Elimina una licenza senza codici né attivazioni. */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $used = Database::get_var($wpdb->prepare(
            'SELECT (SELECT COUNT(*) FROM ' . Database::table('codici') . ' WHERE licenza_id = %d) + (SELECT COUNT(*) FROM ' . Database::table('attivazioni') . ' WHERE licenza_id = %d)',
            $id,
            $id
        ));
        if ((int) $used > 0) {
            return false;
        }
        Database::query($wpdb->prepare('DELETE FROM ' . Database::table('licenza_post') . ' WHERE licenza_id = %d', $id));
        Database::query($wpdb->prepare('DELETE FROM ' . Database::table('licenze') . ' WHERE id = %d', $id));
        return true;
    }

    /** Licenze che sbloccano un contenuto (riquadro della pagina). */
    public static function set_post_licenses(int $post_id, array $licenza_ids): void
    {
        global $wpdb;
        $valid = array_map('intval', wp_list_pluck(self::all(), 'id'));
        $new = array_values(array_intersect(array_unique(array_map('intval', $licenza_ids)), $valid));
        $old = self::of_post($post_id);
        $table = Database::table('licenza_post');

        foreach (array_diff($old, $new) as $licenza_id) {
            Database::query($wpdb->prepare("DELETE FROM {$table} WHERE licenza_id = %d AND post_id = %d", $licenza_id, $post_id));
        }
        foreach (array_diff($new, $old) as $licenza_id) {
            Database::insert_ignore('licenza_post', ['licenza_id' => $licenza_id, 'post_id' => $post_id]);
        }

        if (get_post_type($post_id) === 'sfwd-courses') {
            foreach (array_merge(array_diff($old, $new), array_diff($new, $old)) as $licenza_id) {
                LearnDash::sync_users(self::users($licenza_id));
            }
        }
    }

    /** Sostituisce i contenuti di una licenza; restituisce i post aggiunti o tolti. */
    private static function replace_posts(int $licenza_id, array $post_ids): array
    {
        global $wpdb;
        $new = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        $old = self::posts($licenza_id);
        $table = Database::table('licenza_post');

        $removed = array_diff($old, $new);
        foreach ($removed as $post_id) {
            Database::query($wpdb->prepare("DELETE FROM {$table} WHERE licenza_id = %d AND post_id = %d", $licenza_id, $post_id));
        }
        $added = array_diff($new, $old);
        foreach ($added as $post_id) {
            Database::insert_ignore('licenza_post', ['licenza_id' => $licenza_id, 'post_id' => $post_id]);
        }
        return array_merge($removed, $added);
    }

    private static function has_courses(array $post_ids): bool
    {
        foreach ($post_ids as $post_id) {
            if (get_post_type($post_id) === 'sfwd-courses') {
                return true;
            }
        }
        return false;
    }

    /**
     * Configurazione tardiva dello SKU: collega i codici acquistati non revocati
     * arrivati prima e crea le attivazioni con la loro scadenza (decorrenza
     * dall'acquisto). Riprende anche i codici già collegati che un errore a metà
     * ha lasciato senza attivazione. Con il lock dell'ordine, come nel push: la
     * regola sulla quantità vale per ordine.
     */
    private static function configure_sku($licenza): void
    {
        global $wpdb;
        $codes = Database::get_results($wpdb->prepare(
            'SELECT c.* FROM ' . Database::table('codici') . " c
             WHERE c.tipo = 'acquistato' AND c.revocato_il IS NULL
               AND ((c.licenza_id IS NULL AND c.sku = %s)
                 OR (c.licenza_id = %d AND c.destinatario_sub IS NOT NULL
                     AND NOT EXISTS (SELECT 1 FROM " . Database::table('attivazioni') . ' a WHERE a.codice_id = c.id)))
             ORDER BY c.ordine_id, c.id_esterno',
            $licenza->sku,
            (int) $licenza->id
        ));
        foreach ($codes as $code) {
            $lock = 'rcl_ordine_' . $code->ordine_id;
            if (!Database::lock($lock, 5)) {
                Log::warning('Codice acquistato non collegato: ordine in elaborazione, si riprova al prossimo salvataggio della licenza.', ['ordine' => (int) $code->ordine_id]);
                continue;
            }
            try {
                $linked = Database::update('codici', ['licenza_id' => (int) $licenza->id], ['id' => (int) $code->id, 'licenza_id' => null]);
                $code = Database::get_code((int) $code->id);
                if ((int) $code->licenza_id !== (int) $licenza->id || $code->revocato_il !== null) {
                    continue;
                }
                $esito = Activations::assign_purchase($code);
            } finally {
                Database::unlock($lock);
            }
            if ($linked || $esito !== 'codice_libero') {
                Log::info('Codice acquistato collegato alla licenza configurata.', [
                    'codice'  => Codes::mask($code->codice),
                    'ordine'  => (int) $code->ordine_id,
                    'licenza' => (int) $licenza->id,
                    'esito'   => $esito,
                ]);
            }
        }
    }
}
