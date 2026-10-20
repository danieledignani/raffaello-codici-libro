<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schema delle tabelle e accesso ai dati.
 *
 * - rcl_licenze      : catalogo; lo SKU collega i prodotti del Bookshop.
 * - rcl_licenza_post : contenuti sbloccati da ogni licenza (pagine, articoli, corsi).
 * - rcl_codici       : codici stampati (condivisi) e acquistati (monouso, dal Bookshop).
 * - rcl_attivazioni  : chi ha accesso a quale licenza e fino a quando. La colonna
 *                      chiave (UNIQUE) rende atomici riscatti e assegnazioni.
 *
 * Le date delle colonne nuove sono in UTC. rcl_codice_post e rcl_riscatti sono le
 * tabelle della 1.4: restano e le legge solo la migrazione.
 */
class Database
{
    /** Restituisce il nome completo di una tabella del plugin. */
    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rcl_' . $name;
    }

    /** Adesso in UTC, nel formato delle colonne DATETIME. */
    public static function now(): string
    {
        return current_time('mysql', true);
    }

    /**
     * Crea o aggiorna le tabelle, verifica lo schema e, arrivando dalla 1.4,
     * migra i dati. Vero solo se tutto è riuscito: chi la chiama aggiorna
     * rcl_db_version solo allora, altrimenti si riprova alla richiesta successiva
     * (anche quando un'altra richiesta sta già migrando).
     */
    public static function create_tables(): bool
    {
        global $wpdb;

        if (!self::lock('rcl_migrazione', 0)) {
            return false;
        }

        try {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset_collate = $wpdb->get_charset_collate();

            $licenze = self::table('licenze');
            $licenza_post = self::table('licenza_post');
            $codici = self::table('codici');
            $attivazioni = self::table('attivazioni');

            $sql = [];

            $sql[] = "CREATE TABLE {$licenze} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                nome VARCHAR(255) NOT NULL,
                sku VARCHAR(100) NULL DEFAULT NULL,
                durata_giorni INT UNSIGNED NULL DEFAULT NULL,
                attiva TINYINT(1) NOT NULL DEFAULT 1,
                data_creazione DATETIME NOT NULL,
                data_modifica DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY sku (sku)
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$licenza_post} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                licenza_id BIGINT UNSIGNED NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY licenza_post (licenza_id,post_id),
                KEY post_id (post_id)
            ) {$charset_collate};";

            // Sui siti che arrivano dalla 1.4 resta anche la vecchia colonna data_scadenza.
            $sql[] = "CREATE TABLE {$codici} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codice VARCHAR(64) NOT NULL,
                tipo VARCHAR(20) NOT NULL DEFAULT 'stampato',
                licenza_id BIGINT UNSIGNED NULL DEFAULT NULL,
                descrizione VARCHAR(255) NULL DEFAULT NULL,
                attivo TINYINT(1) NOT NULL DEFAULT 1,
                attivabile_fino DATETIME NULL DEFAULT NULL,
                id_esterno BIGINT UNSIGNED NULL DEFAULT NULL,
                ordine_id BIGINT UNSIGNED NULL DEFAULT NULL,
                riga_ordine BIGINT UNSIGNED NULL DEFAULT NULL,
                sku VARCHAR(100) NULL DEFAULT NULL,
                destinatario_sub VARCHAR(64) NULL DEFAULT NULL,
                scade_il DATETIME NULL DEFAULT NULL,
                revocato_il DATETIME NULL DEFAULT NULL,
                data_creazione DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY codice (codice),
                UNIQUE KEY id_esterno (id_esterno),
                KEY licenza_id (licenza_id),
                KEY ordine_id (ordine_id),
                KEY sku (sku)
            ) {$charset_collate};";

            $sql[] = "CREATE TABLE {$attivazioni} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                chiave VARCHAR(100) NOT NULL,
                licenza_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NULL DEFAULT NULL,
                sub VARCHAR(64) NULL DEFAULT NULL,
                codice_id BIGINT UNSIGNED NULL DEFAULT NULL,
                origine VARCHAR(20) NOT NULL,
                ordine_id BIGINT UNSIGNED NULL DEFAULT NULL,
                scade_il DATETIME NULL DEFAULT NULL,
                stato VARCHAR(20) NOT NULL DEFAULT 'attiva',
                ip VARCHAR(45) NULL DEFAULT NULL,
                data_creazione DATETIME NOT NULL,
                data_modifica DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY chiave (chiave),
                KEY user_id (user_id),
                KEY sub (sub),
                KEY licenza_id (licenza_id),
                KEY codice_id (codice_id),
                KEY ordine_id (ordine_id),
                KEY scade_il (scade_il)
            ) {$charset_collate};";

            foreach ($sql as $statement) {
                dbDelta($statement);
            }
            foreach ($sql as $statement) {
                if (!self::schema_ok($statement)) {
                    Log::error('Schema incompleto dopo dbDelta: versione non aggiornata, si riprova alla prossima richiesta.');
                    return false;
                }
            }

            // Senza transazioni una migrazione interrotta resta a metà: niente migrazione né versione nuova.
            $altri = self::non_innodb();
            if ($altri) {
                Log::error('Tabelle non InnoDB: versione non aggiornata, si riprova alla prossima richiesta.', ['tabelle' => $altri]);
                return false;
            }
            if (self::migration_pending()) {
                Migration::run();
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('Migrazione non riuscita (' . get_class($e) . '): si riprova alla prossima richiesta.');
            return false;
        } finally {
            self::unlock('rcl_migrazione');
        }
    }

    /** La versione salvata è della 1.4: i suoi dati non sono ancora migrati. */
    private static function migration_pending(): bool
    {
        $from = get_option('rcl_db_version');
        return $from && version_compare($from, '1.5.0', '<');
    }

    /**
     * Tabelle non InnoDB, come "nome (motore)": quelle del plugin e, finché la migrazione dalla 1.4
     * non è fatta, anche le sue.
     */
    public static function non_innodb(): array
    {
        global $wpdb;
        $names = ['licenze', 'licenza_post', 'codici', 'attivazioni'];
        if (self::migration_pending()) {
            array_push($names, 'codice_post', 'riscatti');
        }
        $tables = array_map([self::class, 'table'], $names);
        $out = [];
        foreach (self::get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name IN (' . implode(', ', array_fill(0, count($tables), '%s')) . ')', $tables)) as $t) {
            if (strcasecmp((string) $t->Engine, 'InnoDB') !== 0) {
                $out[] = $t->Name . ' (' . $t->Engine . ')';
            }
        }
        return $out;
    }

    /**
     * dbDelta non segnala gli errori: colonne e indici UNIQUE dello statement
     * devono esistere davvero.
     */
    private static function schema_ok(string $statement): bool
    {
        global $wpdb;
        preg_match('/CREATE TABLE (\S+)/', $statement, $table);
        preg_match_all('/^\s*(\w+) (?:BIGINT|INT|TINYINT|VARCHAR|DATETIME)/m', $statement, $columns);
        preg_match_all('/UNIQUE KEY \w+ \(([^)]+)\)/', $statement, $uniques);
        preg_match_all('/^\s*KEY (\w+) \(/m', $statement, $keys);

        if (array_diff($columns[1], $wpdb->get_col("SHOW COLUMNS FROM {$table[1]}"))) {
            return false;
        }
        $have = [];
        $names = [];
        foreach ($wpdb->get_results("SHOW INDEX FROM {$table[1]}") as $index) {
            $names[$index->Key_name] = true;
            if (!(int) $index->Non_unique) {
                $have[$index->Key_name][(int) $index->Seq_in_index] = $index->Column_name;
            }
        }
        foreach ($have as $key => $cols) {
            ksort($cols);
            $have[$key] = implode(',', $cols);
        }
        return !array_diff($uniques[1], $have) && !array_diff($keys[1], array_keys($names));
    }

    /**
     * Lock di MySQL per nome, atteso al massimo $wait secondi. È legato alla
     * connessione: si rilascia da solo se il processo muore e nessun altro può
     * toglierlo. Il nome ha il prefisso delle tabelle.
     */
    public static function lock(string $name, int $wait): bool
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $wpdb->prefix . $name, $wait)) === '1';
    }

    public static function unlock(string $name): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $wpdb->prefix . $name)) !== '1') {
            Log::warning('Lock non rilasciato: non era di questa connessione.', ['lock' => $name]);
        }
    }

    /* ----------------------------------------------------------------------
     * Query: un errore del database diventa un'eccezione, così l'endpoint
     * risponde 500 invece di prendere decisioni su una lettura fallita. Il
     * messaggio non riporta SQL né valori, che finirebbero nei log.
     * -------------------------------------------------------------------- */

    /** Query di scrittura: restituisce le righe toccate. */
    public static function query(string $sql): int
    {
        return (int) self::run('query', $sql);
    }

    public static function get_row(string $sql)
    {
        return self::run('get_row', $sql);
    }

    public static function get_var(string $sql)
    {
        return self::run('get_var', $sql);
    }

    public static function get_col(string $sql): array
    {
        return self::run('get_col', $sql);
    }

    public static function get_results(string $sql): array
    {
        return self::run('get_results', $sql);
    }

    /**
     * Un metodo di wpdb con i suoi errori automatici spenti: scriverebbero nel log la query, con codici
     * e sub, e last_error li ripete ("Duplicate entry"). Nel log solo l'operazione e la tabella.
     */
    private static function run(string $method, string $sql)
    {
        global $wpdb;
        $prev = $wpdb->suppress_errors(true);
        try {
            $res = $wpdb->$method($sql);
        } finally {
            $wpdb->suppress_errors($prev);
        }
        // query() segnala l'errore con false, le letture solo con last_error.
        if ($method === 'query' ? $res === false : $wpdb->last_error !== '') {
            preg_match('/^\s*(\w+)/', $sql, $op);
            preg_match('/\b' . preg_quote($wpdb->prefix, '/') . '\w+/', $sql, $table);
            Log::error($method === 'query' ? 'Scrittura non riuscita.' : 'Lettura non riuscita.', ['operazione' => strtoupper($op[1] ?? ''), 'tabella' => $table[0] ?? '']);
            throw new \RuntimeException('Errore del database');
        }
        return $res;
    }

    /** Valori per una query: NULL senza apici, interi come %d, il resto come %s. */
    private static function values(array $data): string
    {
        global $wpdb;
        $out = [];
        foreach ($data as $value) {
            $out[] = $value === null ? 'NULL' : $wpdb->prepare(is_int($value) ? '%d' : '%s', $value);
        }
        return implode(', ', $out);
    }

    /** INSERT: restituisce l'id della riga nuova. */
    public static function insert(string $table, array $data): int
    {
        global $wpdb;
        self::query('INSERT INTO ' . self::table($table) . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . self::values($data) . ')');
        return (int) $wpdb->insert_id;
    }

    /** INSERT IGNORE: 1 se la riga è nuova, 0 se un indice UNIQUE l'ha rifiutata. */
    public static function insert_ignore(string $table, array $data): int
    {
        return self::query('INSERT IGNORE INTO ' . self::table($table) . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . self::values($data) . ')');
    }

    /** UPDATE con valori e condizioni in uguaglianza (NULL compreso). */
    public static function update(string $table, array $data, array $where): int
    {
        global $wpdb;
        $set = [];
        foreach ($data as $col => $value) {
            $set[] = $col . ' = ' . self::values([$value]);
        }
        $cond = [];
        foreach ($where as $col => $value) {
            $cond[] = $value === null ? $col . ' IS NULL' : $col . ' = ' . self::values([$value]);
        }
        return self::query('UPDATE ' . self::table($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond));
    }

    /** Esegue $fn in una transazione (sulle tabelle InnoDB). */
    public static function transaction(callable $fn): void
    {
        self::query('START TRANSACTION');
        try {
            $fn();
            self::query('COMMIT');
        } catch (\Throwable $e) {
            self::query('ROLLBACK');
            throw $e;
        }
    }

    /* ----------------------------------------------------------------------
     * Codici e accesso
     * -------------------------------------------------------------------- */

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
        return self::get_row($wpdb->prepare('SELECT * FROM ' . self::table('codici') . ' WHERE codice = %s', $codice_norm));
    }

    /** Recupera un codice dall'id. */
    public static function get_code(int $id)
    {
        global $wpdb;
        return self::get_row($wpdb->prepare('SELECT * FROM ' . self::table('codici') . ' WHERE id = %d', $id));
    }

    /**
     * Condizioni di un'attivazione valida (alias a, licenza l, codice c in LEFT
     * JOIN): attiva, non scaduta, licenza attiva, codice non revocato. Il codice
     * revocato decide anche se una gara ha lasciato l'attivazione attiva.
     */
    public static function valid_activation(): string
    {
        return "a.stato = 'attiva' AND l.attiva = 1
               AND (a.codice_id IS NULL OR c.revocato_il IS NULL)
               AND (a.scade_il IS NULL OR a.scade_il > %s)";
    }

    /**
     * Accesso a un contenuto: un'attivazione valida dell'utente di una licenza
     * che contiene il contenuto.
     */
    public static function user_has_access(int $user_id, int $post_id): bool
    {
        global $wpdb;
        return (bool) self::get_var($wpdb->prepare(
            'SELECT 1
             FROM ' . self::table('attivazioni') . ' a
             JOIN ' . self::table('licenze') . ' l ON l.id = a.licenza_id
             JOIN ' . self::table('licenza_post') . ' lp ON lp.licenza_id = a.licenza_id
             LEFT JOIN ' . self::table('codici') . ' c ON c.id = a.codice_id
             WHERE a.user_id = %d AND lp.post_id = %d AND ' . self::valid_activation() . '
             LIMIT 1',
            $user_id,
            $post_id,
            self::now()
        ));
    }
}
