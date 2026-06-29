<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Accesso ai dati: schema delle tabelle e query principali.
 *
 * Modello dati (Ipotesi A):
 * - rcl_codici        : i codici stampati sui libri (condivisi per edizione).
 * - rcl_codice_post   : associazione N–N tra codice e contenuti sbloccati.
 * - rcl_riscatti      : abbinamento codice ↔ utente registrato ad ogni riscatto.
 */
class Database
{
    /** Restituisce il nome completo di una tabella del plugin. */
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rcl_' . $name;
    }

    /** Crea/aggiorna le tabelle del plugin tramite dbDelta. */
    public static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $codici = self::table('codici');
        $codice_post = self::table('codice_post');
        $riscatti = self::table('riscatti');

        $sql = [];

        $sql[] = "CREATE TABLE {$codici} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codice VARCHAR(64) NOT NULL,
            descrizione VARCHAR(255) NULL,
            attivo TINYINT(1) NOT NULL DEFAULT 1,
            data_scadenza DATETIME NULL DEFAULT NULL,
            data_creazione DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY codice (codice)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$codice_post} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codice_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY codice_post (codice_id, post_id),
            KEY post_id (post_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$riscatti} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codice_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            data_riscatto DATETIME NOT NULL,
            ip VARCHAR(45) NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY codice_user (codice_id, user_id),
            KEY user_id (user_id)
        ) {$charset_collate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    /**
     * Normalizza un codice per il confronto: maiuscolo e senza spazi/trattini.
     * Lo stesso formato va usato sia in scrittura sia in lettura.
     */
    public static function normalize_code(string $codice): string
    {
        $codice = strtoupper(trim($codice));
        return preg_replace('/[\s\-]+/', '', $codice);
    }

    /** Recupera un codice dalla forma normalizzata. */
    public static function find_code(string $codice_norm)
    {
        global $wpdb;
        $table = self::table('codici');
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE codice = %s", $codice_norm)
        );
    }

    /** Recupera un codice dall'id. */
    public static function get_code(int $id)
    {
        global $wpdb;
        $table = self::table('codici');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    /**
     * Inserisce un nuovo codice. Restituisce l'id inserito o WP_Error
     * se il codice esiste già.
     */
    public static function insert_code(string $codice_norm, ?string $descrizione, bool $attivo, ?string $data_scadenza)
    {
        global $wpdb;
        $table = self::table('codici');

        if (self::find_code($codice_norm)) {
            return new \WP_Error('codice_duplicato', __('Codice già presente.', 'raffaello-codici-libro'));
        }

        $ok = $wpdb->insert(
            $table,
            [
                'codice'         => $codice_norm,
                'descrizione'    => $descrizione,
                'attivo'         => $attivo ? 1 : 0,
                'data_scadenza'  => $data_scadenza ?: null,
                'data_creazione' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        return $ok ? (int) $wpdb->insert_id : new \WP_Error('insert_fallito', __('Inserimento non riuscito.', 'raffaello-codici-libro'));
    }

    /** Aggiorna i dati di un codice. */
    public static function update_code(int $id, ?string $descrizione, bool $attivo, ?string $data_scadenza): void
    {
        global $wpdb;
        $wpdb->update(
            self::table('codici'),
            [
                'descrizione'   => $descrizione,
                'attivo'        => $attivo ? 1 : 0,
                'data_scadenza' => $data_scadenza ?: null,
            ],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }

    /** Elimina un codice e le associazioni/riscatti collegati. */
    public static function delete_code(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::table('codici'), ['id' => $id], ['%d']);
        $wpdb->delete(self::table('codice_post'), ['codice_id' => $id], ['%d']);
        $wpdb->delete(self::table('riscatti'), ['codice_id' => $id], ['%d']);
    }

    /** Elenco paginato dei codici, con conteggio riscatti. */
    public static function list_codes(int $per_page = 50, int $offset = 0, string $search = ''): array
    {
        global $wpdb;
        $codici = self::table('codici');
        $riscatti = self::table('riscatti');

        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE c.codice LIKE %s OR c.descrizione LIKE %s';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT c.*, (SELECT COUNT(*) FROM {$riscatti} r WHERE r.codice_id = c.id) AS riscatti
                FROM {$codici} c {$where}
                ORDER BY c.data_creazione DESC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /** Numero totale di codici (per la paginazione). */
    public static function count_codes(string $search = ''): int
    {
        global $wpdb;
        $codici = self::table('codici');
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$codici} WHERE codice LIKE %s OR descrizione LIKE %s", $like, $like)
            );
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$codici}");
    }

    /** Imposta i contenuti (post id) sbloccati da un codice, sostituendo i precedenti. */
    public static function set_code_posts(int $codice_id, array $post_ids): void
    {
        global $wpdb;
        $table = self::table('codice_post');
        $wpdb->delete($table, ['codice_id' => $codice_id], ['%d']);
        foreach (array_unique(array_filter(array_map('intval', $post_ids))) as $post_id) {
            $wpdb->insert($table, ['codice_id' => $codice_id, 'post_id' => $post_id], ['%d', '%d']);
        }
    }

    /**
     * Restituisce un codice dalla forma normalizzata, creandolo (attivo, senza
     * scadenza) se non esiste. Usato dal campo per-pagina, dove l'editor digita
     * direttamente il codice del libro.
     */
    public static function find_or_create_code(string $codice_norm, ?string $descrizione = null): int
    {
        $existing = self::find_code($codice_norm);
        if ($existing) {
            return (int) $existing->id;
        }
        $res = self::insert_code($codice_norm, $descrizione, true, null);
        return is_wp_error($res) ? 0 : (int) $res;
    }

    /** Restituisce i codici (id + stringa) che sbloccano un contenuto. */
    public static function get_post_codes(int $post_id): array
    {
        global $wpdb;
        $codice_post = self::table('codice_post');
        $codici = self::table('codici');
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.codice
                 FROM {$codice_post} cp
                 JOIN {$codici} c ON c.id = cp.codice_id
                 WHERE cp.post_id = %d
                 ORDER BY c.codice ASC",
                $post_id
            )
        );
    }

    /**
     * Imposta i codici che sbloccano un contenuto, sostituendo solo i legami di
     * QUESTO contenuto (non tocca gli altri contenuti collegati agli stessi codici).
     */
    public static function set_post_codes(int $post_id, array $code_ids): void
    {
        global $wpdb;
        $table = self::table('codice_post');
        $wpdb->delete($table, ['post_id' => $post_id], ['%d']);
        foreach (array_unique(array_filter(array_map('intval', $code_ids))) as $codice_id) {
            $wpdb->insert($table, ['codice_id' => $codice_id, 'post_id' => $post_id], ['%d', '%d']);
        }
    }

    /** Restituisce gli id dei contenuti sbloccati da un codice. */
    public static function get_code_posts(int $codice_id): array
    {
        global $wpdb;
        $table = self::table('codice_post');
        return array_map('intval', $wpdb->get_col(
            $wpdb->prepare("SELECT post_id FROM {$table} WHERE codice_id = %d", $codice_id)
        ));
    }

    /**
     * Registra il riscatto di un codice da parte di un utente.
     * La chiave univoca (codice_id, user_id) rende l'operazione idempotente.
     */
    public static function record_redemption(int $codice_id, int $user_id, ?string $ip): void
    {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO " . self::table('riscatti') . " (codice_id, user_id, data_riscatto, ip)
                 VALUES (%d, %d, %s, %s)",
                $codice_id,
                $user_id,
                current_time('mysql'),
                $ip
            )
        );
    }

    /**
     * Verifica se l'utente ha accesso a un contenuto: deve esistere un riscatto
     * dell'utente per un codice attivo e non scaduto collegato a quel contenuto.
     */
    public static function user_has_access(int $user_id, int $post_id): bool
    {
        global $wpdb;
        $riscatti = self::table('riscatti');
        $codice_post = self::table('codice_post');
        $codici = self::table('codici');

        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                 FROM {$riscatti} r
                 JOIN {$codice_post} cp ON cp.codice_id = r.codice_id
                 JOIN {$codici} c ON c.id = r.codice_id
                 WHERE r.user_id = %d AND cp.post_id = %d
                   AND c.attivo = 1
                   AND (c.data_scadenza IS NULL OR c.data_scadenza >= %s)
                 LIMIT 1",
                $user_id,
                $post_id,
                current_time('mysql')
            )
        );

        return (bool) $found;
    }

    /** Elenco paginato dei riscatti con dati di codice e utente. */
    public static function list_redemptions(int $per_page = 50, int $offset = 0): array
    {
        global $wpdb;
        $riscatti = self::table('riscatti');
        $codici = self::table('codici');
        $users = $wpdb->users;

        $sql = "SELECT r.*, c.codice, u.user_login, u.user_email
                FROM {$riscatti} r
                JOIN {$codici} c ON c.id = r.codice_id
                LEFT JOIN {$users} u ON u.ID = r.user_id
                ORDER BY r.data_riscatto DESC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, $per_page, $offset));
    }

    /** Numero totale di riscatti. */
    public static function count_redemptions(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table('riscatti'));
    }
}
