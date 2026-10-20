<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Protezione dei file dei materiali.
 *
 * I file stanno in uploads/rcl_materiali, con un .htaccess di diniego come la
 * cartella dei prodotti scaricabili di WooCommerce, e si scaricano solo da
 * Download. Gli allegati sono privati: WordPress li esclude dalla REST pubblica
 * e dalle pagine allegato. I file salvati prima della protezione li sposta un
 * amministratore dall'avviso in bacheca. Con nginx il .htaccess non vale: il
 * controllo di salute se ne accorge e mostra la regola da aggiungere.
 */
class FileProtection
{
    const DIR = 'rcl_materiali';

    /** Parametro che il media modal del meta box aggiunge ai suoi upload. */
    const UPLOAD_PARAM = 'rcl_materiale';

    const CHECK_TRANSIENT = 'rcl_protezione_file';

    /** Materiali a posto all'ultimo controllo: l'avviso non rilegge i file per 10 minuti. */
    const MATERIALS_OK_TRANSIENT = 'rcl_materiali_a_posto';

    /** Riga di wp_options usata come lock della protezione: "token|timestamp" del proprietario. */
    const LOCK_OPTION = 'rcl_protezione_lock';
    const LOCK_MAX_AGE = 600;

    /** Parametri degli esiti mostrati dopo le azioni, tolti poi dalla barra degli indirizzi. */
    const RESULT_ARGS = ['rcl_protetti', 'rcl_falliti', 'rcl_occupato', 'rcl_ricontrollato'];

    const HTACCESS = "# Raffaello Codici Libro: i materiali si scaricano solo tramite il plugin.\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order deny,allow\n"
        . "    Deny from all\n"
        . "</IfModule>\n";

    public function register(): void
    {
        add_filter('wp_insert_attachment_data', [$this, 'insert_private'], 10, 2);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_post_rcl_proteggi_file', [$this, 'protect_all']);
        add_action('admin_post_rcl_ricontrolla', [$this, 'recheck']);
        add_filter('removable_query_args', [$this, 'removable_query_args']);

        // Filtro "Materiali protetti" della libreria media: vista elenco, griglia e modal.
        add_filter('views_upload', [$this, 'media_view']);
        add_action('pre_get_posts', [$this, 'media_list_query']);
        add_filter('ajax_query_attachments_args', [$this, 'media_grid_query']);
        add_action('wp_enqueue_media', [$this, 'media_filter_script']);

        if (self::is_material_upload()) {
            // Il filtro upload_dir lo toglie after_upload. Se l'upload fallisce resta attivo, ma solo per
            // il resto di questa richiesta, che serve un solo upload e finisce con l'errore in JSON.
            add_filter('wp_handle_upload_prefilter', [$this, 'before_upload']);
            add_filter('wp_handle_upload', [$this, 'after_upload']);
            // Niente anteprime dei PDF né miniature: nella cartella protetta sarebbero immagini rotte.
            add_filter('fallback_intermediate_image_sizes', '__return_empty_array');
            add_filter('intermediate_image_sizes_advanced', '__return_empty_array');
        }
    }

    /** Prepara la cartella protetta e ci dirotta wp_upload_dir() fino alla fine dell'upload. */
    public function before_upload($file)
    {
        if (!self::create_files()) {
            $file['error'] = __('Impossibile preparare la cartella protetta dei materiali: il file non è stato caricato.', 'raffaello-codici-libro');
            return $file;
        }
        add_filter('upload_dir', [$this, 'upload_dir']);
        return $file;
    }

    public function after_upload($upload)
    {
        remove_filter('upload_dir', [$this, 'upload_dir']);
        return $upload;
    }

    public function upload_dir($uploads)
    {
        $uploads['subdir'] = '/' . self::DIR . $uploads['subdir'];
        $uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
        $uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
        return $uploads;
    }

    /** Un allegato con il file nella cartella protetta nasce privato. */
    public function insert_private($data, $postarr)
    {
        if (!empty($postarr['file']) && self::in_folder($postarr['file'])) {
            $data['post_status'] = 'private';
        }
        return $data;
    }

    /** Vero se l'intero insieme di file dell'allegato è nella cartella protetta e l'allegato è privato. */
    public static function is_protected(int $attachment_id): bool
    {
        return self::set_state($attachment_id)['state'] === 'protetto';
    }

    /**
     * Porta nella cartella protetta l'intero insieme di file dell'allegato e lo rende privato. È una
     * riconciliazione: parte da set_state(), quindi un nuovo tentativo riprende un lavoro interrotto.
     * Vero solo se alla fine set_state() dice "protetto".
     */
    public static function protect(int $attachment_id): bool
    {
        $s = self::set_state($attachment_id);
        if ($s['state'] === 'fuori') {
            if (!self::bring_in($attachment_id, $s['set'], $s['fuori'])) {
                return false;
            }
            $s = self::set_state($attachment_id);
        }
        if ($s['state'] === 'non_privato' && self::make_private($attachment_id)) {
            $s = self::set_state($attachment_id);
        }
        return $s['state'] === 'protetto';
    }

    /**
     * Materiali da sistemare: "scoperti" (insieme non tutto nella cartella, o allegato non privato)
     * e "mancanti" (un file dell'insieme non c'è da nessuna parte: da verificare a mano).
     *
     * @return array{scoperti: int[], mancanti: int[]}
     */
    public static function unprotected(): array
    {
        global $wpdb;
        $ids = [];
        $values = Database::get_col(
            $wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", Materials::META_KEY)
        );
        foreach ($values as $value) {
            foreach ((array) maybe_unserialize($value) as $m) {
                $ids[] = (int) $m['attachment_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        _prime_post_caches($ids, false, true);

        $result = ['scoperti' => [], 'mancanti' => []];
        foreach ($ids as $id) {
            $state = self::set_state($id)['state'];
            if ($state === 'mancante') {
                $result['mancanti'][] = $id;
            } elseif ($state !== 'protetto') {
                $result['scoperti'][] = $id;
            }
        }
        return $result;
    }

    /**
     * Stato dell'insieme di file dell'allegato (principale, originale non scalato, miniature, backup):
     * "protetto" (tutti nella cartella, nessuno fuori, allegato privato), "non_privato", "fuori" (con i
     * file da portare nella cartella) o "mancante" (un file non c'è né all'origine né nella cartella).
     * Se l'allegato punta già alla cartella, un file diverso con lo stesso nome all'origine è di un altro
     * allegato e non conta; un derivato che c'è solo all'origine invece è rimasto indietro.
     */
    private static function set_state(int $attachment_id): array
    {
        $file = get_attached_file($attachment_id);
        $set = $file ? self::file_set($attachment_id, $file) : null;
        if (!$set) {
            return ['state' => 'mancante'];
        }
        $fuori = [];
        foreach ($set['names'] as $i => $name) {
            $at_to = is_file($set['to'] . '/' . $name);
            $ours = is_file($set['from'] . '/' . $name) && (!$set['inside']
                || ($at_to ? self::same_file($set['from'] . '/' . $name, $set['to'] . '/' . $name) : $i > 0));
            if (!$at_to && !$ours) {
                return ['state' => 'mancante'];
            }
            if ($ours) {
                $fuori[] = $name;
            }
        }
        if ($fuori || !$set['inside']) {
            return ['state' => 'fuori', 'set' => $set, 'fuori' => $fuori];
        }
        return ['state' => get_post_field('post_status', $attachment_id) === 'private' ? 'protetto' : 'non_privato'];
    }

    /** Cartella d'origine, cartella protetta, percorso relativo e nomi dei file dell'allegato (il principale per primo). */
    private static function file_set(int $attachment_id, string $file): ?array
    {
        $base = wp_normalize_path(wp_get_upload_dir()['basedir']);
        $file = wp_normalize_path($file);
        if (strpos($file, $base . '/') !== 0) {
            return null;
        }
        $relative = substr($file, strlen($base) + 1);
        $inside = strpos($relative, self::DIR . '/') === 0;
        if ($inside) {
            $relative = substr($relative, strlen(self::DIR) + 1);
        }
        $meta = wp_get_attachment_metadata($attachment_id);
        $backups = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        $names = array_merge(
            [$relative, $meta['original_image'] ?? ''],
            is_array($meta) ? wp_list_pluck($meta['sizes'] ?? [], 'file') : [],
            is_array($backups) ? wp_list_pluck($backups, 'file') : []
        );
        return [
            'from'     => dirname($base . '/' . $relative),
            'to'       => dirname($base . '/' . self::DIR . '/' . $relative),
            'relative' => $relative,
            'inside'   => $inside,
            // Solo nomi di file: i metadati non possono far uscire dalla cartella dell'allegato.
            'names'    => array_values(array_unique(array_filter(array_map('wp_basename', $names)))),
        ];
    }

    /**
     * Porta nella cartella i file rimasti fuori; se uno non riesce riporta indietro quelli spostati.
     * I metadati seguono il file principale. Vero se sono stati spostati tutti.
     */
    private static function bring_in(int $attachment_id, array $set, array $fuori): bool
    {
        if (!self::create_files() || !wp_mkdir_p($set['to'])) {
            return false;
        }
        $moved = [];
        foreach ($fuori as $name) {
            $result = self::move_file($set['from'] . '/' . $name, $set['to'] . '/' . $name);
            if ($result === false) {
                self::move_back($moved, $set['to'], $set['from']);
                $moved = null;
                break;
            }
            if ($result === 'moved') {
                $moved[] = $name;
            }
        }
        $main = $set['names'][0];
        // Il principale è nella cartella (spostato ora, o rimasto lì da un ritorno indietro non riuscito): i metadati lo seguono.
        if (!$set['inside'] && !file_exists($set['from'] . '/' . $main) && is_file($set['to'] . '/' . $main)
            && !self::point_to_folder($attachment_id, $set['relative'])) {
            if ($moved !== null) {
                self::move_back($moved, $set['to'], $set['from']);
            }
            return false;
        }
        return $moved !== null;
    }

    /** Riporta i file da una cartella all'altra, dall'ultimo al primo, quanto più possibile. */
    private static function move_back(array $names, string $from, string $to): void
    {
        foreach (array_reverse($names) as $name) {
            self::move_file($from . '/' . $name, $to . '/' . $name);
        }
    }

    /**
     * Sposta un file senza mai sovrascrivere: link() fallisce se la destinazione esiste, e senza link()
     * il file non si sposta. "present" se nella destinazione c'era già lo stesso file, false se non riesce.
     *
     * @return string|false
     */
    private static function move_file(string $src, string $dst)
    {
        if (file_exists($dst)) {
            // Lo stesso file, da un link() interrotto prima di togliere l'origine: basta toglierla.
            return self::same_file($src, $dst) && @unlink($src) ? 'present' : false;
        }
        return @link($src, $dst) && @unlink($src) ? 'moved' : false;
    }

    private static function same_file(string $a, string $b): bool
    {
        $sa = @stat($a);
        $sb = @stat($b);
        return $sa && $sb && $sa['ino'] && $sa['ino'] === $sb['ino'] && $sa['dev'] === $sb['dev'];
    }

    /** Fa puntare l'allegato al suo file nella cartella protetta: _wp_attached_file e metadato file. */
    private static function point_to_folder(int $attachment_id, string $relative): bool
    {
        $old = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (!update_attached_file($attachment_id, wp_get_upload_dir()['basedir'] . '/' . self::DIR . '/' . $relative)) {
            return false;
        }
        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta) && isset($meta['file'])) {
            $meta['file'] = self::DIR . '/' . $relative;
            if (!wp_update_attachment_metadata($attachment_id, $meta)) {
                update_post_meta($attachment_id, '_wp_attached_file', $old);
                return false;
            }
        }
        return true;
    }

    private static function make_private(int $attachment_id): bool
    {
        if (get_post_field('post_status', $attachment_id) === 'private') {
            return true;
        }
        // wp_update_post rifiltrerebbe titolo e descrizione con i permessi dell'utente corrente.
        global $wpdb;
        if (!$wpdb->update($wpdb->posts, ['post_status' => 'private'], ['ID' => $attachment_id])) {
            return false;
        }
        clean_post_cache($attachment_id);
        return true;
    }

    /** Azione "Proteggi i file dei materiali": sposta i file scoperti e riferisce l'esito. */
    public function protect_all(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non hai i permessi per questa operazione.', 'raffaello-codici-libro'), 403);
        }
        check_admin_referer('rcl_proteggi_file');

        $back = self::back_url();
        $token = self::acquire_lock();
        if ($token === null) {
            wp_safe_redirect(add_query_arg('rcl_occupato', 1, $back));
            exit;
        }
        try {
            $scoperti = self::unprotected()['scoperti'];
        } catch (\RuntimeException $e) {
            self::release_lock($token);
            wp_die(esc_html__('Protezione dei file non riuscita: i materiali non si leggono. Riprova; se si ripete, guarda il log.', 'raffaello-codici-libro'));
        }
        $protetti = 0;
        $falliti = 0;
        try {
            foreach ($scoperti as $id) {
                if (self::protect($id)) {
                    $protetti++;
                } else {
                    $falliti++;
                }
            }
        } finally {
            self::release_lock($token);
        }
        delete_transient(self::MATERIALS_OK_TRANSIENT);
        wp_safe_redirect(add_query_arg(['rcl_protetti' => $protetti, 'rcl_falliti' => $falliti], $back));
        exit;
    }

    /**
     * Lock della protezione: restituisce il token del proprietario, o null se lo tiene un altro.
     * INSERT IGNORE è atomico grazie alla chiave unica di option_name; add_option() no, perché
     * legge con get_option() e poi scrive con INSERT ... ON DUPLICATE KEY UPDATE.
     */
    private static function acquire_lock(): ?string
    {
        global $wpdb;
        $token = wp_generate_password(20, false) . '|' . time();
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $token))) {
            return $token;
        }
        $old = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION));
        $parts = explode('|', $old);
        if (time() - (int) end($parts) < self::LOCK_MAX_AGE) {
            return null;
        }
        // Lock scaduto: lo prende solo chi sostituisce proprio il valore letto.
        $taken = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, self::LOCK_OPTION, $old));
        return $taken ? $token : null;
    }

    /** Rilascia il lock solo se è ancora di questo proprietario. */
    private static function release_lock(string $token): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $token));
    }

    /** "Ricontrolla ora": ripete il controllo di salute e torna alla pagina senza i parametri dell'azione. */
    public function recheck(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Non hai i permessi per questa operazione.', 'raffaello-codici-libro'), 403);
        }
        check_admin_referer('rcl_ricontrolla');
        set_transient(self::CHECK_TRANSIENT, self::check(), DAY_IN_SECONDS);
        wp_safe_redirect(add_query_arg('rcl_ricontrollato', 1, self::back_url()));
        exit;
    }

    /**
     * Controllo di salute: prova a scaricare via HTTP un file sonda dalla cartella.
     * "protetta" solo se la sonda è negata (401/403) mentre un file di controllo
     * fuori dalla cartella arriva; "esposta" solo se arriva il contenuto della sonda;
     * il resto (timeout, 404, 500, sfida di Cloudflare, scrittura fallita) non basta per dirlo.
     *
     * @return string 'protetta', 'esposta' o 'non_verificabile'
     */
    public static function check(): string
    {
        if (!self::create_files()) {
            return 'non_verificabile';
        }
        $uploads = wp_get_upload_dir();
        // Nome casuale: nessuna cache (Cloudflare compresa) può rispondere al posto del server.
        $token = wp_generate_password(20, false);
        $name = 'rcl-sonda-' . $token . '.txt';
        $probe = self::folder() . '/' . $name;
        $control = wp_normalize_path($uploads['basedir']) . '/' . $name;
        try {
            if (file_put_contents($probe, $token) !== strlen($token) || file_put_contents($control, $token) !== strlen($token)) {
                return 'non_verificabile';
            }
            $response = wp_remote_get($uploads['baseurl'] . '/' . self::DIR . '/' . $name, ['timeout' => 3]);
            if (wp_remote_retrieve_body($response) === $token) {
                return 'esposta';
            }
            if (!in_array((int) wp_remote_retrieve_response_code($response), [401, 403], true)) {
                return 'non_verificabile';
            }
            $response = wp_remote_get($uploads['baseurl'] . '/' . $name, ['timeout' => 3]);
            return wp_remote_retrieve_body($response) === $token ? 'protetta' : 'non_verificabile';
        } finally {
            wp_delete_file($probe);
            wp_delete_file($control);
        }
    }

    /** Avvisi agli amministratori, solo in bacheca e nelle pagine del plugin. */
    public function notices(): void
    {
        $screen = get_current_screen();
        if (!current_user_can('manage_options') || ($screen->id !== 'dashboard' && strpos($screen->id, 'rcl_') === false)) {
            return;
        }
        $this->result_notices();
        $this->unprotected_notice();
        $this->health_notice();
    }

    private function result_notices(): void
    {
        if (isset($_GET['rcl_occupato'])) {
            self::print_notice('warning', __('Raffaello Licenze: la protezione dei file è già in corso. Riprova tra qualche minuto.', 'raffaello-codici-libro'));
        }
        if (isset($_GET['rcl_protetti'], $_GET['rcl_falliti'])) {
            $protetti = (int) $_GET['rcl_protetti'];
            $falliti = (int) $_GET['rcl_falliti'];
            if ($falliti > 0) {
                /* translators: 1: file protetti, 2: file non riusciti */
                self::print_notice('error', sprintf(_n(
                    'Raffaello Licenze: protezione riuscita per %1$d file, non riuscita per %2$d, che resta scoperto. Succede se nella cartella protetta c\'è già un file con lo stesso nome o se il server non permette di collegare i file con link(); puoi riprovare.',
                    'Raffaello Licenze: protezione riuscita per %1$d file, non riuscita per %2$d, che restano scoperti. Succede se nella cartella protetta c\'è già un file con lo stesso nome o se il server non permette di collegare i file con link(); puoi riprovare.',
                    $falliti,
                    'raffaello-codici-libro'
                ), $protetti, $falliti));
            } else {
                /* translators: %d: file protetti */
                self::print_notice('success', sprintf(__('Raffaello Licenze: protezione riuscita per %d file.', 'raffaello-codici-libro'), $protetti));
            }
        }
        if (isset($_GET['rcl_ricontrollato']) && get_transient(self::CHECK_TRANSIENT) === 'protetta') {
            self::print_notice('success', __('Raffaello Licenze: i file dei materiali risultano protetti.', 'raffaello-codici-libro'));
        }
    }

    /** Materiali salvati prima della protezione: l'avviso resta finché ce n'è uno da sistemare. */
    private function unprotected_notice(): void
    {
        if (get_transient(self::MATERIALS_OK_TRANSIENT)) {
            return;
        }
        try {
            $da_sistemare = self::unprotected();
        } catch (\RuntimeException $e) {
            // Una lettura fallita non è "tutto a posto": niente transient, si riprova alla prossima pagina.
            self::print_notice('error', __('Raffaello Licenze: controllo dei file dei materiali non riuscito, si riprova alla prossima pagina. Dettagli nel log.', 'raffaello-codici-libro'));
            return;
        }
        $scoperti = count($da_sistemare['scoperti']);
        if ($scoperti === 0 && !$da_sistemare['mancanti']) {
            set_transient(self::MATERIALS_OK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);
            return;
        }
        $mancanti = array_map(function ($id) {
            return Materials::attachment_name($id) ?: '#' . $id;
        }, $da_sistemare['mancanti']);
        ?>
        <div class="notice notice-warning">
            <?php if ($scoperti > 0) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <p>
                        <strong><?php echo esc_html(sprintf(
                            /* translators: %d: numero di file */
                            _n('Raffaello Licenze: %d file di materiali non è protetto.', 'Raffaello Licenze: %d file di materiali non sono protetti.', $scoperti, 'raffaello-codici-libro'),
                            $scoperti
                        )); ?></strong>
                        <?php esc_html_e('Chi conosce l\'indirizzo di un file scoperto lo scarica senza codice. Il pulsante sposta i file nella cartella protetta: i loro indirizzi diretti smettono di funzionare, anche dove sono usati fuori dai materiali.', 'raffaello-codici-libro'); ?>
                    </p>
                    <input type="hidden" name="action" value="rcl_proteggi_file" />
                    <?php wp_nonce_field('rcl_proteggi_file'); ?>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Proteggi i file dei materiali', 'raffaello-codici-libro'); ?></button></p>
                </form>
            <?php endif; ?>
            <?php if ($mancanti) : ?>
                <p><?php echo esc_html(sprintf(
                    /* translators: %s: nomi dei file */
                    __('Raffaello Licenze, da verificare: file dei materiali non trovati né al loro posto né nella cartella protetta: %s. Ricaricali dal riquadro «Materiali sbloccabili» della pagina, oppure togli quei materiali.', 'raffaello-codici-libro'),
                    implode(', ', $mancanti)
                )); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function health_notice(): void
    {
        $esito = get_transient(self::CHECK_TRANSIENT);
        if ($esito === false) {
            $esito = self::check();
            set_transient(self::CHECK_TRANSIENT, $esito, DAY_IN_SECONDS);
        }
        if ($esito === 'protetta') {
            return;
        }

        $folder_url = wp_get_upload_dir()['baseurl'] . '/' . self::DIR . '/';
        $recheck_url = wp_nonce_url(admin_url('admin-post.php?action=rcl_ricontrolla'), 'rcl_ricontrolla');

        if ($esito === 'esposta') {
            $rule = 'location ^~ ' . wp_parse_url($folder_url, PHP_URL_PATH) . " {\n    deny all;\n}";
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Raffaello Licenze: i file dei materiali non sono protetti.', 'raffaello-codici-libro'); ?></strong>
                    <?php esc_html_e('Chi conosce l\'indirizzo di un file lo scarica senza codice: il server non applica il file .htaccess della cartella protetta (succede con nginx). Aggiungi questa regola alla configurazione nginx del sito:', 'raffaello-codici-libro'); ?>
                </p>
                <pre><?php echo esc_html($rule); ?></pre>
                <p><a href="<?php echo esc_url($recheck_url); ?>"><?php esc_html_e('Ricontrolla ora', 'raffaello-codici-libro'); ?></a></p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Raffaello Licenze: impossibile verificare la protezione dei file dei materiali.', 'raffaello-codici-libro'); ?></strong>
                <?php
                printf(
                    /* translators: %s: URL dell'index.html della cartella protetta */
                    esc_html__('Il server non riesce a scaricare un file di prova dal sito. Verifica a mano: %s deve rispondere 403 (accesso negato), non con una pagina vuota.', 'raffaello-codici-libro'),
                    '<a href="' . esc_url($folder_url . 'index.html') . '" target="_blank" rel="noopener">' . esc_html($folder_url . 'index.html') . '</a>'
                );
                ?>
                <a href="<?php echo esc_url($recheck_url); ?>"><?php esc_html_e('Ricontrolla ora', 'raffaello-codici-libro'); ?></a>
            </p>
        </div>
        <?php
    }

    public function removable_query_args($args)
    {
        return array_merge($args, self::RESULT_ARGS);
    }

    /** Vista "Materiali protetti" nella libreria media in modalità elenco. */
    public function media_view($views)
    {
        if (!current_user_can('read_private_posts')) {
            return $views;
        }
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
            $wpdb->esc_like(self::DIR . '/') . '%'
        ));
        $views[self::DIR] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url(admin_url('upload.php?mode=list&' . self::DIR . '=1')),
            empty($_GET[self::DIR]) ? '' : ' class="current" aria-current="page"',
            esc_html__('Materiali protetti', 'raffaello-codici-libro'),
            $count
        );
        return $views;
    }

    /** Elenco della libreria media ristretto ai file nella cartella protetta. */
    public function media_list_query($query): void
    {
        global $pagenow;
        if (is_admin() && $pagenow === 'upload.php' && $query->is_main_query() && !empty($_GET[self::DIR])) {
            $query->set('meta_query', [self::media_meta_clause()]);
        }
    }

    /** Griglia e modal: la voce "Materiali protetti" del filtro manda rcl_materiali nella query AJAX. */
    public function media_grid_query($args)
    {
        if (!empty($_REQUEST['query'][self::DIR])) {
            $args['meta_query'] = [self::media_meta_clause()];
        }
        return $args;
    }

    /** Aggiunge la voce al filtro della griglia e dei modal, per chi vede gli allegati privati. */
    public function media_filter_script(): void
    {
        if (!current_user_can('read_private_posts')) {
            return;
        }
        wp_enqueue_script('rcl-media-filtro', RCL_PLUGIN_URL . 'assets/js/admin-media-filtro.js', ['media-views'], RCL_VERSION, true);
        wp_localize_script('rcl-media-filtro', 'rclMediaFiltro', [
            'label' => __('Materiali protetti', 'raffaello-codici-libro'),
        ]);
    }

    private static function media_meta_clause(): array
    {
        return ['key' => '_wp_attached_file', 'value' => self::DIR . '/', 'compare' => 'LIKE'];
    }

    private static function print_notice(string $type, string $text): void
    {
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
    }

    /** Pagina da cui è partita l'azione, senza gli esiti precedenti. */
    private static function back_url(): string
    {
        return remove_query_arg(self::RESULT_ARGS, wp_get_referer() ?: admin_url());
    }

    /** Crea cartella, .htaccess di diniego e index.html se mancano; vero se cartella e .htaccess ci sono. */
    private static function create_files(): bool
    {
        $dir = self::folder();
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        foreach (['.htaccess' => self::HTACCESS, 'index.html' => ''] as $name => $content) {
            if (!file_exists($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $content);
            }
        }
        return is_file($dir . '/.htaccess');
    }

    private static function folder(): string
    {
        return wp_normalize_path(wp_get_upload_dir()['basedir']) . '/' . self::DIR;
    }

    private static function in_folder(string $file): bool
    {
        return strpos(wp_normalize_path($file), self::folder() . '/') === 0;
    }

    /** Richiesta di upload del core (async-upload.php) partita dal media modal del meta box. */
    private static function is_material_upload(): bool
    {
        return isset($_POST['action'], $_POST[self::UPLOAD_PARAM]) && $_POST['action'] === 'upload-attachment';
    }
}
