<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backoffice, menu "Licenze": catalogo delle licenze, codici stampati e
 * acquistati (con import CSV), attivazioni per l'assistenza, impostazioni.
 * Tutte le azioni richiedono manage_options e un nonce.
 */
class Admin
{
    const CAP = 'manage_options';
    const PER_PAGE = 50;

    /** Risultati di una ricerca in POST, senza pagine. */
    const MAX_RICERCA = 100;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function menu(): void
    {
        add_menu_page(__('Raffaello Licenze', 'raffaello-codici-libro'), __('Licenze', 'raffaello-codici-libro'), self::CAP, 'rcl_licenze', [$this, 'page_licenze'], 'dashicons-unlock', 56);
        add_submenu_page('rcl_licenze', __('Licenze', 'raffaello-codici-libro'), __('Licenze', 'raffaello-codici-libro'), self::CAP, 'rcl_licenze', [$this, 'page_licenze']);
        add_submenu_page('rcl_licenze', __('Codici', 'raffaello-codici-libro'), __('Codici', 'raffaello-codici-libro'), self::CAP, 'rcl_codici', [$this, 'page_codici']);
        add_submenu_page('rcl_licenze', __('Attivazioni', 'raffaello-codici-libro'), __('Attivazioni', 'raffaello-codici-libro'), self::CAP, 'rcl_attivazioni', [$this, 'page_attivazioni']);
        add_submenu_page('rcl_licenze', __('Impostazioni', 'raffaello-codici-libro'), __('Impostazioni', 'raffaello-codici-libro'), self::CAP, 'rcl_impostazioni', [$this, 'page_impostazioni']);
    }

    public function enqueue($hook): void
    {
        if (strpos((string) $hook, 'rcl_') === false) {
            return;
        }
        wp_enqueue_style('rcl-admin', RCL_PLUGIN_URL . 'assets/css/admin.css', [], RCL_VERSION);
    }

    /* ----------------------------------------------------------------------
     * Azioni
     * -------------------------------------------------------------------- */

    public function handle_actions(): void
    {
        if (!isset($_REQUEST['rcl_admin_action']) || !current_user_can(self::CAP)) {
            return;
        }
        $action = sanitize_key(wp_unslash($_REQUEST['rcl_admin_action']));
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

        try {
            switch ($action) {
                case 'save_licenza':
                    check_admin_referer('rcl_save_licenza');
                    $this->action_save_licenza($id);
                    break;
                case 'delete_licenza':
                    check_admin_referer('rcl_delete_licenza_' . $id);
                    self::back('rcl_licenze', ['rcl_msg' => Licenses::delete($id) ? 'licenza_eliminata' : 'licenza_in_uso']);
                    break;
                case 'save_codice':
                    check_admin_referer('rcl_save_codice');
                    $this->action_save_codice($id);
                    break;
                case 'toggle_codice':
                    check_admin_referer('rcl_toggle_codice_' . $id);
                    $code = Database::get_code($id);
                    if ($code) {
                        Database::update('codici', ['attivo' => (int) $code->attivo ? 0 : 1], ['id' => $id]);
                    }
                    self::back('rcl_codici', ['rcl_msg' => 'codice_salvato']);
                    break;
                case 'delete_codice':
                    check_admin_referer('rcl_delete_codice_' . $id);
                    self::back('rcl_codici', ['rcl_msg' => $this->delete_codice($id) ? 'codice_eliminato' : 'codice_in_uso']);
                    break;
                case 'import_csv':
                    check_admin_referer('rcl_import_csv');
                    $this->action_import_csv();
                    break;
                case 'grant':
                    check_admin_referer('rcl_grant');
                    $this->action_grant();
                    break;
                case 'revoke':
                    check_admin_referer('rcl_revoke_' . $id);
                    self::back('rcl_attivazioni', ['rcl_msg' => Activations::revoke($id) ? 'revocata' : 'non_modificata']);
                    break;
                case 'reactivate':
                    check_admin_referer('rcl_reactivate_' . $id);
                    self::back('rcl_attivazioni', ['rcl_msg' => Activations::reactivate($id) ? 'riattivata' : 'non_riattivabile']);
                    break;
                case 'save_impostazioni':
                    check_admin_referer('rcl_impostazioni');
                    // Segreto: vuoto = resta quello che c'è; se non è valido non si salva niente.
                    $segreto = isset($_POST['segreto']) ? trim((string) wp_unslash($_POST['segreto'])) : '';
                    if ($segreto !== '' && !preg_match('/^[\x21-\x7e]{' . Config::MIN_LENGTH . ',256}$/', $segreto)) {
                        self::back('rcl_impostazioni', ['rcl_msg' => 'segreto_non_valido']);
                    }
                    update_option(Frontend::OPTION_MIEI_CONTENUTI, isset($_POST['pagina']) ? (int) $_POST['pagina'] : 0);
                    if ($segreto !== '') {
                        if (!Config::save($segreto)) {
                            self::back('rcl_impostazioni', ['rcl_msg' => 'segreto_non_salvato']);
                        }
                        Log::info('Segreto del Bookshop salvato dalle impostazioni.', ['admin' => get_current_user_id()]);
                    }
                    self::back('rcl_impostazioni', ['rcl_msg' => $segreto !== '' ? 'segreto_salvato' : 'impostazioni_salvate']);
                    break;
                case 'genera_segreto':
                    check_admin_referer('rcl_genera_segreto');
                    $segreto = bin2hex(random_bytes(32));
                    if (!Config::save($segreto)) {
                        self::back('rcl_impostazioni', ['rcl_msg' => 'segreto_non_salvato']);
                    }
                    Log::info('Segreto del Bookshop generato dalle impostazioni.', ['admin' => get_current_user_id()]);
                    // Si mostra una volta sola, alla prossima apertura della pagina. Nel transient c'è solo
                    // l'impronta: il segreto resta in un solo posto del database.
                    if (!set_transient('rcl_segreto_nuovo_' . get_current_user_id(), wp_hash($segreto), 10 * MINUTE_IN_SECONDS)) {
                        self::back('rcl_impostazioni', ['rcl_msg' => 'segreto_non_mostrato']);
                    }
                    self::back('rcl_impostazioni', ['rcl_msg' => 'segreto_generato']);
                    break;
                case 'cancella_segreto':
                    check_admin_referer('rcl_cancella_segreto');
                    if (!Config::delete()) {
                        self::back('rcl_impostazioni', ['rcl_msg' => 'segreto_non_cancellato']);
                    }
                    Log::info('Segreto del Bookshop cancellato dalle impostazioni.', ['admin' => get_current_user_id()]);
                    self::back('rcl_impostazioni', ['rcl_msg' => 'segreto_cancellato']);
                    break;
                case 'riallinea':
                    check_admin_referer('rcl_riallinea');
                    list($n, $ko) = LearnDash::realign_all();
                    self::back('rcl_impostazioni', ['rcl_msg' => 'riallineato', 'n' => $n, 'ko' => $ko]);
                    break;
            }
        } catch (\RuntimeException $e) {
            Log::error('Azione admin ' . $action . ' non riuscita (' . get_class($e) . ').');
            wp_die(esc_html__('Operazione non riuscita. Riprova; se si ripete, guarda il log.', 'raffaello-codici-libro'));
        }
    }

    private function action_save_licenza(int $id): void
    {
        $nome = isset($_POST['nome']) ? sanitize_text_field(wp_unslash($_POST['nome'])) : '';
        if ($nome === '') {
            self::back('rcl_licenze', ['view' => 'edit', 'id' => $id, 'rcl_msg' => 'nome_vuoto']);
        }
        $durata = isset($_POST['durata_giorni']) ? (int) $_POST['durata_giorni'] : 0;
        $post_ids = array_merge(
            isset($_POST['post_ids']) ? (array) wp_unslash($_POST['post_ids']) : [],
            isset($_POST['post_ids_add']) ? (array) wp_unslash($_POST['post_ids_add']) : []
        );

        $res = Licenses::save(
            $id,
            $nome,
            isset($_POST['sku']) ? sanitize_text_field(wp_unslash($_POST['sku'])) : null,
            $durata > 0 ? $durata : null,
            !empty($_POST['attiva']),
            $post_ids
        );
        if (is_wp_error($res)) {
            self::back('rcl_licenze', ['view' => 'edit', 'id' => $id, 'rcl_msg' => $res->get_error_code()]);
        }

        // Codici stampati aggiunti dal form della licenza.
        $raw = isset($_POST['nuovi_codici']) ? (string) wp_unslash($_POST['nuovi_codici']) : '';
        $fino = self::date_from_input(isset($_POST['nuovi_fino']) ? sanitize_text_field(wp_unslash($_POST['nuovi_fino'])) : '');
        $aggiunti = $saltati = 0;
        foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $token) {
            $norm = Database::normalize_code($token);
            if ($norm === '') {
                continue;
            }
            self::add_printed_code($norm, (int) $res, $fino) ? $aggiunti++ : $saltati++;
        }

        self::back('rcl_licenze', ['view' => 'edit', 'id' => (int) $res, 'rcl_msg' => 'licenza_salvata', 'ins' => $aggiunti, 'skip' => $saltati]);
    }

    /**
     * Codice stampato per una licenza: nuovo, oppure esistente e senza licenza.
     * Falso se è troppo lungo, acquistato o già di un'altra licenza.
     */
    private static function add_printed_code(string $norm, int $licenza_id, ?string $fino): bool
    {
        if (strlen($norm) > 64) {
            return false;
        }
        $code = Database::find_code($norm);
        if ($code) {
            return $code->tipo === Codes::STAMPATO
                && ((int) $code->licenza_id === $licenza_id
                    || Database::update('codici', ['licenza_id' => $licenza_id], ['id' => (int) $code->id, 'licenza_id' => null]) === 1);
        }
        return Database::insert_ignore('codici', [
            'codice'          => $norm,
            'tipo'            => Codes::STAMPATO,
            'licenza_id'      => $licenza_id,
            'attivabile_fino' => $fino,
            'data_creazione'  => Database::now(),
        ]) === 1;
    }

    private function action_save_codice(int $id): void
    {
        $attivo = !empty($_POST['attivo']) ? 1 : 0;
        $code = $id > 0 ? Database::get_code($id) : null;

        // Degli acquistati si cambia solo lo stato: il resto arriva dal Bookshop.
        if ($code && $code->tipo === Codes::ACQUISTATO) {
            Database::update('codici', ['attivo' => $attivo], ['id' => $id]);
            self::back('rcl_codici', ['rcl_msg' => 'codice_salvato']);
        }

        $licenza_id = isset($_POST['licenza_id']) ? (int) $_POST['licenza_id'] : 0;
        $data = [
            'licenza_id'      => $licenza_id > 0 && Licenses::get($licenza_id) ? $licenza_id : null,
            'descrizione'     => isset($_POST['descrizione']) && trim((string) wp_unslash($_POST['descrizione'])) !== '' ? sanitize_text_field(wp_unslash($_POST['descrizione'])) : null,
            'attivo'          => $attivo,
            'attivabile_fino' => self::date_from_input(isset($_POST['attivabile_fino']) ? sanitize_text_field(wp_unslash($_POST['attivabile_fino'])) : ''),
        ];

        if ($code) {
            Database::update('codici', $data, ['id' => $id]);
            self::back('rcl_codici', ['rcl_msg' => 'codice_salvato']);
        }

        $norm = isset($_POST['codice']) ? Database::normalize_code(sanitize_text_field(wp_unslash($_POST['codice']))) : '';
        if ($norm === '' || strlen($norm) > 64) {
            self::back('rcl_codici', ['view' => 'new', 'rcl_msg' => 'codice_non_valido']);
        }
        $inserted = Database::insert_ignore('codici', $data + [
            'codice'         => $norm,
            'tipo'           => Codes::STAMPATO,
            'data_creazione' => Database::now(),
        ]);
        self::back('rcl_codici', ['rcl_msg' => $inserted ? 'codice_salvato' : 'codice_duplicato']);
    }

    /** Elimina un codice stampato mai riscattato; gli altri si disattivano. */
    private function delete_codice(int $id): bool
    {
        global $wpdb;
        $code = Database::get_code($id);
        if (!$code || $code->tipo !== Codes::STAMPATO
            || Database::get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Database::table('attivazioni') . ' WHERE codice_id = %d', $id))) {
            return false;
        }
        Database::query($wpdb->prepare('DELETE FROM ' . Database::table('codici') . ' WHERE id = %d', $id));
        return true;
    }

    /**
     * Import CSV di codici stampati: codice, licenza (id o SKU), attivabile_fino
     * (opzionale, aaaa-mm-gg o gg/mm/aaaa). Separatore "," o ";"; l'intestazione
     * si salta. Le righe non valide si scartano con il motivo.
     */
    private function action_import_csv(): void
    {
        $file = $_FILES['csv'] ?? null;
        if (!$file || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || $file['size'] > 2 * MB_IN_BYTES) {
            self::back('rcl_codici', ['view' => 'import', 'rcl_msg' => 'file_non_valido']);
        }
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            self::back('rcl_codici', ['view' => 'import', 'rcl_msg' => 'file_non_valido']);
        }

        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);

        $inseriti = 0;
        $errori = [];
        $riga = 0;
        while (($cols = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $riga++;
            $cols = array_map('trim', array_map('strval', $cols));
            if ($cols === [''] || ($riga === 1 && stripos($cols[0], 'codice') !== false)) {
                continue;
            }
            // Il BOM di Excel all'inizio del file.
            $norm = Database::normalize_code(preg_replace('/^\xEF\xBB\xBF/', '', $cols[0]));
            $rif = $cols[1] ?? '';
            $licenza = $rif === '' ? null : (ctype_digit($rif) ? Licenses::get((int) $rif) : Licenses::by_sku($rif));
            $fino = ($cols[2] ?? '') !== '' ? self::date_from_input($cols[2]) : null;

            if ($norm === '' || strlen($norm) > 64) {
                $errori[] = sprintf(__('riga %d: codice mancante o troppo lungo', 'raffaello-codici-libro'), $riga);
            } elseif (!$licenza) {
                $errori[] = sprintf(__('riga %d: licenza "%s" inesistente', 'raffaello-codici-libro'), $riga, $rif);
            } elseif (($cols[2] ?? '') !== '' && $fino === null) {
                $errori[] = sprintf(__('riga %d: data "%s" non valida', 'raffaello-codici-libro'), $riga, $cols[2]);
            } elseif (!Database::insert_ignore('codici', [
                'codice'          => $norm,
                'tipo'            => Codes::STAMPATO,
                'licenza_id'      => (int) $licenza->id,
                'attivabile_fino' => $fino,
                'data_creazione'  => Database::now(),
            ])) {
                $errori[] = sprintf(__('riga %d: codice già presente', 'raffaello-codici-libro'), $riga);
            } else {
                $inseriti++;
            }
        }
        fclose($handle);

        set_transient('rcl_import_' . get_current_user_id(), $errori, HOUR_IN_SECONDS);
        self::back('rcl_codici', ['view' => 'import', 'rcl_msg' => 'import_ok', 'ins' => $inseriti, 'skip' => count($errori)]);
    }

    private function action_grant(): void
    {
        $who = isset($_POST['utente']) ? sanitize_text_field(wp_unslash($_POST['utente'])) : '';
        $user = is_email($who) ? get_user_by('email', $who) : (ctype_digit($who) ? get_user_by('id', (int) $who) : get_user_by('login', $who));
        $licenza = Licenses::get(isset($_POST['licenza_id']) ? (int) $_POST['licenza_id'] : 0);
        if (!$user || !$licenza) {
            self::back('rcl_attivazioni', ['rcl_msg' => 'utente_o_licenza']);
        }
        $esito = Activations::grant((int) $licenza->id, (int) $user->ID, self::date_from_input(isset($_POST['scade_il']) ? sanitize_text_field(wp_unslash($_POST['scade_il'])) : ''));
        Log::info('Attivazione manuale dall\'admin.', ['licenza' => (int) $licenza->id, 'user_id' => (int) $user->ID, 'esito' => $esito, 'admin' => get_current_user_id()]);
        self::back('rcl_attivazioni', ['rcl_msg' => 'grant_' . $esito, 'by' => 'utente', 's' => (int) $user->ID]);
    }

    /** Torna alla pagina indicata con gli argomenti dati. */
    private static function back(string $page, array $args): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php')));
        exit;
    }

    /* ----------------------------------------------------------------------
     * Licenze
     * -------------------------------------------------------------------- */

    public function page_licenze(): void
    {
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'list';
        if ($view === 'edit' || $view === 'new') {
            $this->render_licenza_form(isset($_GET['id']) ? (int) $_GET['id'] : 0);
            return;
        }

        $codici = Database::table('codici');
        $licenze = Database::get_results(
            'SELECT l.*,
                (SELECT COUNT(*) FROM ' . Database::table('licenza_post') . " lp WHERE lp.licenza_id = l.id) AS n_contenuti,
                (SELECT COUNT(*) FROM {$codici} c WHERE c.licenza_id = l.id AND c.tipo = 'stampato') AS n_stampati,
                (SELECT COUNT(*) FROM {$codici} c WHERE c.licenza_id = l.id AND c.tipo = 'acquistato') AS n_acquistati,
                (SELECT COUNT(*) FROM " . Database::table('attivazioni') . " a WHERE a.licenza_id = l.id AND a.stato = 'attiva') AS n_attivazioni
             FROM " . Database::table('licenze') . ' l ORDER BY l.nome, l.id'
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Licenze', 'raffaello-codici-libro'); ?></h1>
            <a href="<?php echo esc_url(self::url('rcl_licenze', ['view' => 'new'])); ?>" class="page-title-action"><?php esc_html_e('Aggiungi licenza', 'raffaello-codici-libro'); ?></a>
            <?php $this->notice(); ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Nome', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('SKU', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Durata (codici stampati)', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Stato', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Contenuti', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Codici stampati', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Codici acquistati', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Attivazioni attive', 'raffaello-codici-libro'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$licenze) : ?>
                    <tr><td colspan="9"><?php esc_html_e('Nessuna licenza.', 'raffaello-codici-libro'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($licenze as $l) :
                    $in_uso = $l->n_stampati + $l->n_acquistati + $l->n_attivazioni > 0;
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(self::url('rcl_licenze', ['view' => 'edit', 'id' => $l->id])); ?>"><strong><?php echo esc_html($l->nome); ?></strong></a></td>
                        <td><?php echo $l->sku !== null ? '<code>' . esc_html($l->sku) . '</code>' : '—'; ?></td>
                        <td><?php echo $l->durata_giorni !== null ? esc_html(sprintf(_n('%d giorno', '%d giorni', (int) $l->durata_giorni, 'raffaello-codici-libro'), $l->durata_giorni)) : esc_html__('illimitata', 'raffaello-codici-libro'); ?></td>
                        <td><?php echo (int) $l->attiva ? esc_html__('Attiva', 'raffaello-codici-libro') : '<strong>' . esc_html__('Disattivata', 'raffaello-codici-libro') . '</strong>'; ?></td>
                        <td><?php echo (int) $l->n_contenuti; ?></td>
                        <td><a href="<?php echo esc_url(self::url('rcl_codici', ['licenza' => $l->id, 'tipo' => 'stampato'])); ?>"><?php echo (int) $l->n_stampati; ?></a></td>
                        <td><a href="<?php echo esc_url(self::url('rcl_codici', ['licenza' => $l->id, 'tipo' => 'acquistato'])); ?>"><?php echo (int) $l->n_acquistati; ?></a></td>
                        <td><?php echo (int) $l->n_attivazioni; ?></td>
                        <td>
                            <a href="<?php echo esc_url(self::url('rcl_licenze', ['view' => 'edit', 'id' => $l->id])); ?>"><?php esc_html_e('Modifica', 'raffaello-codici-libro'); ?></a>
                            <?php if (!$in_uso) : ?>
                                | <a href="<?php echo esc_url(self::action_url('rcl_licenze', 'delete_licenza', (int) $l->id)); ?>" onclick="return confirm('<?php echo esc_js(__('Eliminare la licenza?', 'raffaello-codici-libro')); ?>');"><?php esc_html_e('Elimina', 'raffaello-codici-libro'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_licenza_form(int $id): void
    {
        global $wpdb;
        $licenza = $id ? Licenses::get($id) : null;
        $id = $licenza ? (int) $licenza->id : 0;
        $selected = $id ? Licenses::posts($id) : [];
        $codici = $id ? Database::get_results($wpdb->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM ' . Database::table('attivazioni') . ' a WHERE a.codice_id = c.id) AS n_attivazioni
             FROM ' . Database::table('codici') . " c WHERE c.licenza_id = %d AND c.tipo = 'stampato' ORDER BY c.codice",
            $id
        )) : [];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($licenza ? __('Modifica licenza', 'raffaello-codici-libro') : __('Nuova licenza', 'raffaello-codici-libro')); ?></h1>
            <?php $this->notice(); ?>
            <form method="post" action="<?php echo esc_url(self::url('rcl_licenze')); ?>">
                <?php wp_nonce_field('rcl_save_licenza'); ?>
                <input type="hidden" name="rcl_admin_action" value="save_licenza" />
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="rcl-nome"><?php esc_html_e('Nome', 'raffaello-codici-libro'); ?></label></th>
                        <td><input type="text" id="rcl-nome" name="nome" class="regular-text" required value="<?php echo esc_attr($licenza->nome ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="rcl-sku"><?php esc_html_e('SKU', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <input type="text" id="rcl-sku" name="sku" class="regular-text" value="<?php echo esc_attr($licenza->sku ?? ''); ?>" />
                            <p class="description"><?php esc_html_e('SKU del prodotto licenza sul Bookshop (non l\'ISBN del libro). Vuoto per le licenze dei soli codici stampati. Al salvataggio si collegano i codici acquistati arrivati con questo SKU prima della configurazione.', 'raffaello-codici-libro'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="rcl-durata"><?php esc_html_e('Durata', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <input type="number" min="1" step="1" id="rcl-durata" name="durata_giorni" class="small-text" value="<?php echo esc_attr($licenza->durata_giorni ?? ''); ?>" /> <?php esc_html_e('giorni', 'raffaello-codici-libro'); ?>
                            <p class="description"><?php esc_html_e('Solo per i codici stampati: accesso per questi giorni dal riscatto. Vuoto = senza scadenza. Gli acquisti usano la scadenza ricevuta dal Bookshop.', 'raffaello-codici-libro'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Attiva', 'raffaello-codici-libro'); ?></th>
                        <td><label><input type="checkbox" name="attiva" value="1" <?php checked($licenza ? (int) $licenza->attiva : 1, 1); ?> /> <?php esc_html_e('Disattivandola si sospendono gli accessi di questa licenza (e i suoi corsi LearnDash).', 'raffaello-codici-libro'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Contenuti', 'raffaello-codici-libro'); ?></th>
                        <td>
                            <?php foreach ($selected as $post_id) : ?>
                                <label style="display:block;"><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr($post_id); ?>" checked /> <?php echo esc_html(self::post_label($post_id)); ?></label>
                            <?php endforeach; ?>
                            <?php if ($selected) : ?>
                                <p class="description"><?php esc_html_e('Togli la spunta per scollegare un contenuto.', 'raffaello-codici-libro'); ?></p>
                            <?php endif; ?>
                            <p><label for="rcl-contenuti"><?php esc_html_e('Aggiungi contenuti (pagine, articoli e corsi):', 'raffaello-codici-libro'); ?></label></p>
                            <select id="rcl-contenuti" name="post_ids_add[]" multiple size="12" style="min-width:420px;">
                                <?php foreach (self::selectable_posts() as $p) : ?>
                                    <?php if (!in_array((int) $p->ID, $selected, true)) : ?>
                                        <option value="<?php echo esc_attr($p->ID); ?>"><?php echo esc_html(self::post_label((int) $p->ID, $p)); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Tieni premuto CTRL/CMD per selezionarne più di uno. Le pagine si possono collegare anche dal riquadro "Raffaello Licenze" della pagina.', 'raffaello-codici-libro'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Codici stampati', 'raffaello-codici-libro'); ?></th>
                        <td>
                            <?php if ($codici) : ?>
                                <table class="widefat striped" style="max-width:640px;">
                                    <thead><tr>
                                        <th><?php esc_html_e('Codice', 'raffaello-codici-libro'); ?></th>
                                        <th><?php esc_html_e('Stato', 'raffaello-codici-libro'); ?></th>
                                        <th><?php esc_html_e('Attivabile fino al', 'raffaello-codici-libro'); ?></th>
                                        <th><?php esc_html_e('Attivazioni', 'raffaello-codici-libro'); ?></th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($codici as $c) : ?>
                                        <tr>
                                            <td><a href="<?php echo esc_url(self::url('rcl_codici', ['view' => 'edit', 'id' => $c->id])); ?>"><code><?php echo esc_html($c->codice); ?></code></a></td>
                                            <td><?php echo esc_html(self::code_state($c)); ?></td>
                                            <td><?php echo $c->attivabile_fino !== null ? esc_html(Codes::date($c->attivabile_fino)) : '—'; ?></td>
                                            <td><?php echo (int) $c->n_attivazioni; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                            <p><label for="rcl-nuovi"><?php esc_html_e('Aggiungi codici stampati (uno per riga):', 'raffaello-codici-libro'); ?></label></p>
                            <textarea id="rcl-nuovi" name="nuovi_codici" rows="4" class="large-text code" style="max-width:420px;"></textarea>
                            <p>
                                <label for="rcl-nuovi-fino"><?php esc_html_e('Attivabili fino al', 'raffaello-codici-libro'); ?></label>
                                <input type="date" id="rcl-nuovi-fino" name="nuovi_fino" />
                                <span class="description"><?php esc_html_e('(vuoto = senza termine)', 'raffaello-codici-libro'); ?></span>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button($licenza ? __('Aggiorna', 'raffaello-codici-libro') : __('Crea licenza', 'raffaello-codici-libro')); ?>
            </form>
        </div>
        <?php
    }

    /** Pagine, articoli e corsi collegabili (non cestinati). */
    private static function selectable_posts(): array
    {
        global $wpdb;
        $types = Licenses::post_types();
        if (!$types) {
            return [];
        }
        return Database::get_results($wpdb->prepare(
            "SELECT ID, post_title, post_type FROM {$wpdb->posts}
             WHERE post_type IN (" . implode(', ', array_fill(0, count($types), '%s')) . ")
               AND post_status IN ('publish', 'private', 'draft', 'pending', 'future')
             ORDER BY post_type, post_title",
            $types
        ));
    }

    private static function post_label(int $post_id, $post = null): string
    {
        $post = $post ?: get_post($post_id);
        if (!$post) {
            return sprintf(__('#%d (contenuto eliminato)', 'raffaello-codici-libro'), $post_id);
        }
        return ($post->post_title !== '' ? $post->post_title : __('(senza titolo)', 'raffaello-codici-libro')) . ' (#' . $post->ID . ' · ' . $post->post_type . ')';
    }

    /* ----------------------------------------------------------------------
     * Codici
     * -------------------------------------------------------------------- */

    public function page_codici(): void
    {
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'list';
        if ($view === 'edit' || $view === 'new') {
            $this->render_codice_form(isset($_GET['id']) ? (int) $_GET['id'] : 0);
            return;
        }
        if ($view === 'import') {
            $this->render_import();
            return;
        }

        global $wpdb;
        // La ricerca arriva in POST: il codice cercato non finisce negli URL (cronologia, log
        // del server). In GET solo i filtri dei link interni, con la paginazione.
        $cerca = isset($_POST['rcl_cerca']) && check_admin_referer('rcl_cerca_codici');
        $src = $cerca ? wp_unslash($_POST) : $_GET;
        $f = [
            'tipo'    => isset($src['tipo']) ? sanitize_key($src['tipo']) : '',
            'licenza' => isset($src['licenza']) ? sanitize_key($src['licenza']) : '',
            'ordine'  => isset($src['ordine']) ? (int) $src['ordine'] : 0,
            'stato'   => isset($src['stato']) ? sanitize_key($src['stato']) : '',
            's'       => $cerca && isset($src['s']) ? Database::normalize_code(sanitize_text_field($src['s'])) : '',
        ];
        $now = Database::now();
        $where = ['1=1'];
        if (in_array($f['tipo'], [Codes::STAMPATO, Codes::ACQUISTATO], true)) {
            $where[] = $wpdb->prepare('c.tipo = %s', $f['tipo']);
        }
        if ($f['licenza'] === 'nessuna') {
            $where[] = 'c.licenza_id IS NULL';
        } elseif ((int) $f['licenza'] > 0) {
            $where[] = $wpdb->prepare('c.licenza_id = %d', (int) $f['licenza']);
        }
        if ($f['ordine'] > 0) {
            $where[] = $wpdb->prepare('c.ordine_id = %d', $f['ordine']);
        }
        $scaduto = $wpdb->prepare('((c.attivabile_fino IS NOT NULL AND c.attivabile_fino <= %s) OR (c.scade_il IS NOT NULL AND c.scade_il <= %s))', $now, $now);
        switch ($f['stato']) {
            case 'attivo':
                $where[] = "c.attivo = 1 AND c.revocato_il IS NULL AND NOT {$scaduto}";
                break;
            case 'disattivato':
                $where[] = 'c.attivo = 0';
                break;
            case 'revocato':
                $where[] = 'c.revocato_il IS NOT NULL';
                break;
            case 'scaduto':
                $where[] = $scaduto;
                break;
            case 'in_attesa':
                $where[] = "c.tipo = 'acquistato' AND c.licenza_id IS NULL AND c.revocato_il IS NULL";
                break;
        }
        if ($f['s'] !== '') {
            $where[] = $wpdb->prepare('c.codice LIKE %s', '%' . $wpdb->esc_like($f['s']) . '%');
        }
        $where = implode(' AND ', $where);
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);

        $from = 'FROM ' . Database::table('codici') . ' c LEFT JOIN ' . Database::table('licenze') . " l ON l.id = c.licenza_id WHERE {$where}";
        $total = (int) Database::get_var("SELECT COUNT(*) {$from}");
        $codici = Database::get_results($wpdb->prepare(
            'SELECT c.*, l.nome AS licenza, (SELECT COUNT(*) FROM ' . Database::table('attivazioni') . " a WHERE a.codice_id = c.id) AS n_attivazioni
             {$from} ORDER BY c.id DESC LIMIT %d OFFSET %d",
            $cerca ? self::MAX_RICERCA : self::PER_PAGE,
            $cerca ? 0 : ($paged - 1) * self::PER_PAGE
        ));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Codici', 'raffaello-codici-libro'); ?></h1>
            <a href="<?php echo esc_url(self::url('rcl_codici', ['view' => 'new'])); ?>" class="page-title-action"><?php esc_html_e('Aggiungi codice stampato', 'raffaello-codici-libro'); ?></a>
            <a href="<?php echo esc_url(self::url('rcl_codici', ['view' => 'import'])); ?>" class="page-title-action"><?php esc_html_e('Importa CSV', 'raffaello-codici-libro'); ?></a>
            <?php $this->notice(); ?>

            <form method="post" action="<?php echo esc_url(self::url('rcl_codici')); ?>" class="rcl-filtri">
                <?php wp_nonce_field('rcl_cerca_codici'); ?>
                <input type="hidden" name="rcl_cerca" value="1" />
                <select name="tipo">
                    <option value=""><?php esc_html_e('Tutti i tipi', 'raffaello-codici-libro'); ?></option>
                    <option value="stampato" <?php selected($f['tipo'], 'stampato'); ?>><?php esc_html_e('Stampati', 'raffaello-codici-libro'); ?></option>
                    <option value="acquistato" <?php selected($f['tipo'], 'acquistato'); ?>><?php esc_html_e('Acquistati', 'raffaello-codici-libro'); ?></option>
                </select>
                <select name="licenza">
                    <option value=""><?php esc_html_e('Tutte le licenze', 'raffaello-codici-libro'); ?></option>
                    <option value="nessuna" <?php selected($f['licenza'], 'nessuna'); ?>><?php esc_html_e('Senza licenza', 'raffaello-codici-libro'); ?></option>
                    <?php foreach (Licenses::all() as $l) : ?>
                        <option value="<?php echo esc_attr($l->id); ?>" <?php selected($f['licenza'], (string) $l->id); ?>><?php echo esc_html($l->nome); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="stato">
                    <option value=""><?php esc_html_e('Tutti gli stati', 'raffaello-codici-libro'); ?></option>
                    <?php foreach (['attivo' => __('Attivi', 'raffaello-codici-libro'), 'disattivato' => __('Disattivati', 'raffaello-codici-libro'), 'revocato' => __('Revocati', 'raffaello-codici-libro'), 'scaduto' => __('Scaduti', 'raffaello-codici-libro'), 'in_attesa' => __('In attesa di configurazione', 'raffaello-codici-libro')] as $k => $label) : ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($f['stato'], $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="ordine" min="1" placeholder="<?php esc_attr_e('Ordine', 'raffaello-codici-libro'); ?>" value="<?php echo $f['ordine'] ? esc_attr($f['ordine']) : ''; ?>" style="width:100px;" />
                <input type="search" name="s" placeholder="<?php esc_attr_e('Codice o sua parte', 'raffaello-codici-libro'); ?>" value="<?php echo esc_attr($f['s']); ?>" />
                <button class="button"><?php esc_html_e('Filtra', 'raffaello-codici-libro'); ?></button>
            </form>

            <p class="description"><?php echo esc_html(sprintf(_n('%d codice. I codici acquistati sono mascherati.', '%d codici. I codici acquistati sono mascherati.', $total, 'raffaello-codici-libro'), $total)); ?><?php self::search_limit($cerca, $total); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Codice', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Tipo', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Licenza', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Ordine', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Destinatario (sub)', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Attivabile fino al / Scade il', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Stato', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Attivazioni', 'raffaello-codici-libro'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$codici) : ?>
                    <tr><td colspan="9"><?php esc_html_e('Nessun codice.', 'raffaello-codici-libro'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($codici as $c) :
                    $acquistato = $c->tipo === Codes::ACQUISTATO;
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($acquistato ? Codes::mask($c->codice) : $c->codice); ?></code></td>
                        <td><?php echo esc_html($acquistato ? __('acquistato', 'raffaello-codici-libro') : __('stampato', 'raffaello-codici-libro')); ?></td>
                        <td><?php echo $c->licenza !== null ? esc_html($c->licenza) : ($acquistato ? esc_html(sprintf(__('SKU %s non configurato', 'raffaello-codici-libro'), $c->sku ?? '—')) : '—'); ?></td>
                        <td><?php echo $c->ordine_id !== null ? '<a href="' . esc_url(self::url('rcl_codici', ['ordine' => $c->ordine_id])) . '">#' . esc_html($c->ordine_id) . '</a>' : '—'; ?></td>
                        <td><?php echo $c->destinatario_sub !== null ? esc_html($c->destinatario_sub) : '—'; ?></td>
                        <td><?php echo esc_html(self::code_deadline($c)); ?></td>
                        <td><?php echo esc_html(self::code_state($c)); ?></td>
                        <td><?php echo (int) $c->n_attivazioni ? '<a href="' . esc_url(self::url('rcl_attivazioni', ['by' => 'codice_id', 's' => $c->id])) . '">' . (int) $c->n_attivazioni . '</a>' : '0'; ?></td>
                        <td>
                            <a href="<?php echo esc_url(self::url('rcl_codici', ['view' => 'edit', 'id' => $c->id])); ?>"><?php esc_html_e('Modifica', 'raffaello-codici-libro'); ?></a>
                            | <a href="<?php echo esc_url(self::action_url('rcl_codici', 'toggle_codice', (int) $c->id)); ?>"><?php echo (int) $c->attivo ? esc_html__('Disattiva', 'raffaello-codici-libro') : esc_html__('Attiva', 'raffaello-codici-libro'); ?></a>
                            <?php if (!$acquistato && !(int) $c->n_attivazioni) : ?>
                                | <a href="<?php echo esc_url(self::action_url('rcl_codici', 'delete_codice', (int) $c->id)); ?>" onclick="return confirm('<?php echo esc_js(__('Eliminare il codice?', 'raffaello-codici-libro')); ?>');"><?php esc_html_e('Elimina', 'raffaello-codici-libro'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$cerca) {
                self::pagination($total, $paged);
            } ?>
        </div>
        <?php
    }

    private function render_codice_form(int $id): void
    {
        $code = $id ? Database::get_code($id) : null;
        $acquistato = $code && $code->tipo === Codes::ACQUISTATO;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($code ? __('Modifica codice', 'raffaello-codici-libro') : __('Nuovo codice stampato', 'raffaello-codici-libro')); ?></h1>
            <?php $this->notice(); ?>
            <form method="post" action="<?php echo esc_url(self::url('rcl_codici')); ?>">
                <?php wp_nonce_field('rcl_save_codice'); ?>
                <input type="hidden" name="rcl_admin_action" value="save_codice" />
                <input type="hidden" name="id" value="<?php echo esc_attr($code ? $code->id : 0); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="rcl-codice"><?php esc_html_e('Codice', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <?php if ($code) : ?>
                                <code><?php echo esc_html($acquistato ? Codes::mask($code->codice) : $code->codice); ?></code>
                                (<?php echo esc_html($acquistato ? __('acquistato', 'raffaello-codici-libro') : __('stampato', 'raffaello-codici-libro')); ?>)
                            <?php else : ?>
                                <input type="text" id="rcl-codice" name="codice" class="regular-text" required />
                                <p class="description"><?php esc_html_e('Verrà normalizzato in maiuscolo senza spazi/trattini.', 'raffaello-codici-libro'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($acquistato) : ?>
                        <tr><th><?php esc_html_e('Licenza', 'raffaello-codici-libro'); ?></th><td><?php echo esc_html($code->licenza_id !== null ? Licenses::get((int) $code->licenza_id)->nome : sprintf(__('SKU %s non configurato', 'raffaello-codici-libro'), $code->sku ?? '—')); ?></td></tr>
                        <tr><th><?php esc_html_e('Ordine', 'raffaello-codici-libro'); ?></th><td><?php echo esc_html('#' . $code->ordine_id . ($code->riga_ordine !== null ? ' · ' . sprintf(__('riga %d', 'raffaello-codici-libro'), $code->riga_ordine) : '') . ' · SKU ' . ($code->sku ?? '—') . ' · ' . sprintf(__('licenza Bookshop %d', 'raffaello-codici-libro'), $code->id_esterno)); ?></td></tr>
                        <tr><th><?php esc_html_e('Destinatario (sub)', 'raffaello-codici-libro'); ?></th><td><?php echo esc_html($code->destinatario_sub ?? '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Scade il', 'raffaello-codici-libro'); ?></th><td><?php echo esc_html($code->scade_il !== null ? Codes::date($code->scade_il) : __('senza scadenza', 'raffaello-codici-libro')); ?></td></tr>
                        <tr><th><?php esc_html_e('Revocato il', 'raffaello-codici-libro'); ?></th><td><?php echo esc_html($code->revocato_il !== null ? Codes::date($code->revocato_il) : '—'); ?></td></tr>
                    <?php else : ?>
                        <tr>
                            <th><label for="rcl-licenza"><?php esc_html_e('Licenza', 'raffaello-codici-libro'); ?></label></th>
                            <td>
                                <select id="rcl-licenza" name="licenza_id">
                                    <option value="0"><?php esc_html_e('— nessuna —', 'raffaello-codici-libro'); ?></option>
                                    <?php foreach (Licenses::all() as $l) : ?>
                                        <option value="<?php echo esc_attr($l->id); ?>" <?php selected($code ? (int) $code->licenza_id : 0, (int) $l->id); ?>><?php echo esc_html($l->nome); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="rcl-descr"><?php esc_html_e('Descrizione', 'raffaello-codici-libro'); ?></label></th>
                            <td><input type="text" id="rcl-descr" name="descrizione" class="regular-text" value="<?php echo esc_attr($code->descrizione ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="rcl-fino"><?php esc_html_e('Attivabile fino al', 'raffaello-codici-libro'); ?></label></th>
                            <td>
                                <input type="date" id="rcl-fino" name="attivabile_fino" value="<?php echo esc_attr($code && $code->attivabile_fino !== null ? get_date_from_gmt($code->attivabile_fino, 'Y-m-d') : ''); ?>" />
                                <p class="description"><?php esc_html_e('Termine per riscattare il codice; vuoto = senza termine. Non toglie gli accessi già dati.', 'raffaello-codici-libro'); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('Attivo', 'raffaello-codici-libro'); ?></th>
                        <td><label><input type="checkbox" name="attivo" value="1" <?php checked($code ? (int) $code->attivo : 1, 1); ?> /> <?php esc_html_e('Si può riscattare (gli accessi già dati restano comunque)', 'raffaello-codici-libro'); ?></label></td>
                    </tr>
                </table>
                <?php submit_button($code ? __('Aggiorna', 'raffaello-codici-libro') : __('Crea codice', 'raffaello-codici-libro')); ?>
            </form>
        </div>
        <?php
    }

    private function render_import(): void
    {
        $errori = get_transient('rcl_import_' . get_current_user_id());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Importa codici stampati da CSV', 'raffaello-codici-libro'); ?></h1>
            <?php $this->notice(); ?>
            <?php if (is_array($errori) && $errori && isset($_GET['rcl_msg'])) : ?>
                <div class="notice notice-warning"><p><?php echo esc_html(implode('; ', array_slice($errori, 0, 20)) . (count($errori) > 20 ? ' …' : '')); ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e('Colonne: codice, licenza (id o SKU), attivabile_fino (opzionale, aaaa-mm-gg o gg/mm/aaaa). Separatore "," o ";". L\'eventuale riga di intestazione viene ignorata. Al massimo 2 MB.', 'raffaello-codici-libro'); ?></p>
            <p><code>codice,licenza,attivabile_fino</code><br /><code>MAT1-2026,12,2027-08-31</code><br /><code>STO3-2026,ISBN-DIG-STO3,</code></p>
            <form method="post" action="<?php echo esc_url(self::url('rcl_codici', ['view' => 'import'])); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('rcl_import_csv'); ?>
                <input type="hidden" name="rcl_admin_action" value="import_csv" />
                <input type="file" name="csv" accept=".csv,text/csv" required />
                <?php submit_button(__('Importa', 'raffaello-codici-libro')); ?>
            </form>
        </div>
        <?php
    }

    private static function code_state($c): string
    {
        $now = Database::now();
        if ($c->revocato_il !== null) {
            return sprintf(__('Revocato il %s', 'raffaello-codici-libro'), Codes::date($c->revocato_il));
        }
        if (!(int) $c->attivo) {
            return __('Disattivato', 'raffaello-codici-libro');
        }
        if (($c->attivabile_fino !== null && $c->attivabile_fino <= $now) || ($c->scade_il !== null && $c->scade_il <= $now)) {
            return __('Scaduto', 'raffaello-codici-libro');
        }
        if ($c->tipo === Codes::ACQUISTATO && $c->licenza_id === null) {
            return __('In attesa di configurazione', 'raffaello-codici-libro');
        }
        return __('Attivo', 'raffaello-codici-libro');
    }

    private static function code_deadline($c): string
    {
        $date = $c->tipo === Codes::ACQUISTATO ? $c->scade_il : $c->attivabile_fino;
        return $date !== null ? Codes::date($date) : '—';
    }

    /* ----------------------------------------------------------------------
     * Attivazioni
     * -------------------------------------------------------------------- */

    public function page_attivazioni(): void
    {
        global $wpdb;
        // Email, sub e codice arrivano solo in POST: non finiscono negli URL (cronologia, log del
        // server). In GET solo gli id dei link interni: codice_id, utente, ordine.
        $cerca = isset($_POST['rcl_cerca']) && check_admin_referer('rcl_cerca_attivazioni');
        $src = $cerca ? wp_unslash($_POST) : $_GET;
        $by = isset($src['by']) ? sanitize_key($src['by']) : 'email';
        $term = isset($src['s']) ? trim(sanitize_text_field($src['s'])) : '';
        if (!$cerca && !in_array($by, ['codice_id', 'utente', 'ordine'], true)) {
            $term = '';
        }
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);

        $where = '1=1';
        if ($term !== '') {
            switch ($by) {
                case 'sub':
                    $where = $wpdb->prepare("(a.sub = %s OR a.user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ri_oidc_sub' AND meta_value = %s))", $term, $term);
                    break;
                case 'codice':
                    $where = $wpdb->prepare('c.codice = %s', Database::normalize_code($term));
                    break;
                // Dal link dell'elenco Codici: per id, così il codice acquistato non finisce nell'URL.
                case 'codice_id':
                    $where = $wpdb->prepare('a.codice_id = %d', (int) $term);
                    break;
                case 'ordine':
                    $where = $wpdb->prepare('a.ordine_id = %d', (int) $term);
                    break;
                case 'utente':
                    $where = $wpdb->prepare('a.user_id = %d', (int) $term);
                    break;
                default:
                    $user = get_user_by('email', $term);
                    $sub = $user ? Activations::sub_of((int) $user->ID) : null;
                    $where = !$user ? '1=0' : ($sub !== null
                        ? $wpdb->prepare('(a.user_id = %d OR a.sub = %s)', $user->ID, $sub)
                        : $wpdb->prepare('a.user_id = %d', $user->ID));
            }
        }

        $from = 'FROM ' . Database::table('attivazioni') . ' a
                 JOIN ' . Database::table('licenze') . ' l ON l.id = a.licenza_id
                 LEFT JOIN ' . Database::table('codici') . " c ON c.id = a.codice_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
                 WHERE {$where}";
        $total = (int) Database::get_var("SELECT COUNT(*) {$from}");
        $rows = Database::get_results($wpdb->prepare(
            "SELECT a.*, l.nome AS licenza, c.codice, c.tipo AS codice_tipo, c.revocato_il AS codice_revocato, u.user_email, u.display_name
             {$from} ORDER BY a.id DESC LIMIT %d OFFSET %d",
            $cerca ? self::MAX_RICERCA : self::PER_PAGE,
            $cerca ? 0 : ($paged - 1) * self::PER_PAGE
        ));
        $now = Database::now();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Attivazioni', 'raffaello-codici-libro'); ?></h1>
            <?php $this->notice(); ?>

            <form method="post" action="<?php echo esc_url(self::url('rcl_attivazioni')); ?>" class="rcl-filtri">
                <?php wp_nonce_field('rcl_cerca_attivazioni'); ?>
                <input type="hidden" name="rcl_cerca" value="1" />
                <select name="by">
                    <?php foreach (['email' => __('Email utente', 'raffaello-codici-libro'), 'sub' => __('sub di Identity', 'raffaello-codici-libro'), 'codice' => __('Codice', 'raffaello-codici-libro'), 'ordine' => __('Ordine', 'raffaello-codici-libro')] as $k => $label) : ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($by, $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($term); ?>" class="regular-text" />
                <button class="button"><?php esc_html_e('Cerca', 'raffaello-codici-libro'); ?></button>
            </form>

            <p class="description"><?php echo esc_html(sprintf(_n('%d attivazione.', '%d attivazioni.', $total, 'raffaello-codici-libro'), $total)); ?><?php self::search_limit($cerca, $total); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Utente', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Licenza', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Origine', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Codice', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Ordine', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Attivata il', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Scade il', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Stato', 'raffaello-codici-libro'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="9"><?php esc_html_e('Nessuna attivazione.', 'raffaello-codici-libro'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $a) :
                    // Il codice revocato decide anche se una gara ha lasciato l'attivazione attiva.
                    $revocata = $a->stato === Activations::REVOCATA || $a->codice_revocato !== null;
                    $scaduta = !$revocata && $a->scade_il !== null && $a->scade_il <= $now;
                    ?>
                    <tr>
                        <td>
                            <?php if ($a->user_id !== null) : ?>
                                <a href="<?php echo esc_url(get_edit_user_link((int) $a->user_id)); ?>"><?php echo esc_html($a->display_name ?: '#' . $a->user_id); ?></a><br /><small><?php echo esc_html($a->user_email); ?></small>
                            <?php else : ?>
                                <em><?php esc_html_e('in attesa del primo accesso', 'raffaello-codici-libro'); ?></em>
                            <?php endif; ?>
                            <?php if ($a->sub !== null) : ?><br /><small>sub <?php echo esc_html($a->sub); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo esc_html($a->licenza); ?></td>
                        <td><?php echo esc_html($a->origine); ?></td>
                        <td><?php echo $a->codice !== null ? '<code>' . esc_html($a->codice_tipo === Codes::ACQUISTATO ? Codes::mask($a->codice) : $a->codice) . '</code>' : '—'; ?></td>
                        <td><?php echo $a->ordine_id !== null ? '#' . esc_html($a->ordine_id) : '—'; ?></td>
                        <td><?php echo esc_html(get_date_from_gmt($a->data_creazione, 'd/m/Y H:i')); ?></td>
                        <td><?php echo $a->scade_il !== null ? esc_html(Codes::date($a->scade_il)) : esc_html__('senza scadenza', 'raffaello-codici-libro'); ?></td>
                        <td><?php echo esc_html($revocata ? __('Revocata', 'raffaello-codici-libro') : ($scaduta ? __('Scaduta', 'raffaello-codici-libro') : __('Attiva', 'raffaello-codici-libro'))); ?></td>
                        <td>
                            <?php if (!$revocata) : ?>
                                <a href="<?php echo esc_url(self::action_url('rcl_attivazioni', 'revoke', (int) $a->id)); ?>" onclick="return confirm('<?php echo esc_js(__('Revocare questa attivazione?', 'raffaello-codici-libro')); ?>');"><?php esc_html_e('Revoca', 'raffaello-codici-libro'); ?></a>
                            <?php elseif ($a->codice_revocato === null) : ?>
                                <a href="<?php echo esc_url(self::action_url('rcl_attivazioni', 'reactivate', (int) $a->id)); ?>"><?php esc_html_e('Riattiva', 'raffaello-codici-libro'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$cerca) {
                self::pagination($total, $paged);
            } ?>

            <h2><?php esc_html_e('Attivazione manuale', 'raffaello-codici-libro'); ?></h2>
            <p class="description"><?php esc_html_e('Per l\'assistenza: dà una licenza a un utente. Ripetere il form non crea doppioni; un\'attivazione revocata si riattiva dall\'elenco.', 'raffaello-codici-libro'); ?></p>
            <form method="post" action="<?php echo esc_url(self::url('rcl_attivazioni')); ?>">
                <?php wp_nonce_field('rcl_grant'); ?>
                <input type="hidden" name="rcl_admin_action" value="grant" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="rcl-utente"><?php esc_html_e('Utente', 'raffaello-codici-libro'); ?></label></th>
                        <td><input type="text" id="rcl-utente" name="utente" class="regular-text" required placeholder="<?php esc_attr_e('email, nome utente o id', 'raffaello-codici-libro'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="rcl-licenza"><?php esc_html_e('Licenza', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <select id="rcl-licenza" name="licenza_id" required>
                                <?php foreach (Licenses::all() as $l) : ?>
                                    <option value="<?php echo esc_attr($l->id); ?>"><?php echo esc_html($l->nome); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="rcl-scade"><?php esc_html_e('Scade il', 'raffaello-codici-libro'); ?></label></th>
                        <td><input type="date" id="rcl-scade" name="scade_il" /> <span class="description"><?php esc_html_e('(vuoto = senza scadenza)', 'raffaello-codici-libro'); ?></span></td>
                    </tr>
                </table>
                <?php submit_button(__('Attiva licenza', 'raffaello-codici-libro'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    /* ----------------------------------------------------------------------
     * Impostazioni
     * -------------------------------------------------------------------- */

    public function page_impostazioni(): void
    {
        $config = Config::state();
        $costante = defined(Config::CONSTANT);
        // Il segreto appena generato si mostra una volta, e solo se è quello che vale ora: una
        // costante definita nel frattempo, un'altra generazione o un database copiato lo superano.
        $nuovo = get_transient('rcl_segreto_nuovo_' . get_current_user_id());
        if ($nuovo !== false) {
            delete_transient('rcl_segreto_nuovo_' . get_current_user_id());
        }
        $mostra = $nuovo !== false && $config['segreto'] !== '' && hash_equals($nuovo, wp_hash($config['segreto']));
        $fonti = [
            'wp-config'    => __('wp-config.php (RCL_BOOKSHOP_SECRET), che ha la precedenza sulle impostazioni', 'raffaello-codici-libro'),
            'impostazioni' => $config['problema'] === 'altro_host'
                /* translators: %s: host */
                ? sprintf(__('impostazioni salvate da un altro sito (%s), che qui non valgono', 'raffaello-codici-libro'), $config['host'])
                : __('impostazioni', 'raffaello-codici-libro'),
            ''             => __('nessuna', 'raffaello-codici-libro'),
        ];
        $stati = [
            Health::OK               => __('OK', 'raffaello-codici-libro'),
            Health::BLOCCANTE        => __('Bloccante', 'raffaello-codici-libro'),
            Health::ATTENZIONE       => __('Attenzione', 'raffaello-codici-libro'),
            Health::INFO             => __('Info', 'raffaello-codici-libro'),
            Health::NON_VERIFICABILE => __('Non verificabile', 'raffaello-codici-libro'),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Impostazioni', 'raffaello-codici-libro'); ?></h1>
            <?php $this->notice(); ?>
            <?php if ($mostra) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('Copia ora il segreto nelle impostazioni del plugin «Raffaello Licenze — Bookshop»: non verrà più mostrato.', 'raffaello-codici-libro'); ?></strong></p>
                    <p><input type="text" class="large-text code" readonly value="<?php echo esc_attr($config['segreto']); ?>" onfocus="this.select();" /></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Stato della configurazione', 'raffaello-codici-libro'); ?></h2>
            <table class="widefat striped rcl-controlli">
                <tbody>
                <?php foreach (Health::problems() as $c) : ?>
                    <tr>
                        <td><span class="rcl-stato rcl-stato--<?php echo esc_attr($c['stato']); ?>"><?php echo esc_html($stati[$c['stato']]); ?></span></td>
                        <th scope="row"><?php echo esc_html($c['titolo']); ?></th>
                        <td>
                            <?php echo esc_html($c['testo']); ?>
                            <?php if ($c['link'] !== null && $c['stato'] !== Health::OK) : ?>
                                <a href="<?php echo esc_url($c['link']); ?>"><?php esc_html_e('Correggi', 'raffaello-codici-libro'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" action="<?php echo esc_url(self::url('rcl_impostazioni')); ?>">
                <?php wp_nonce_field('rcl_impostazioni'); ?>
                <input type="hidden" name="rcl_admin_action" value="save_impostazioni" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="rcl-pagina"><?php esc_html_e('Pagina "I miei contenuti"', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <?php wp_dropdown_pages(['name' => 'pagina', 'id' => 'rcl-pagina', 'selected' => (int) get_option(Frontend::OPTION_MIEI_CONTENUTI), 'show_option_none' => __('— nessuna —', 'raffaello-codici-libro'), 'option_none_value' => '0']); ?>
                            <p class="description"><?php esc_html_e('La pagina con lo shortcode [raffaello_miei_contenuti]: il messaggio dopo un riscatto rimanda qui.', 'raffaello-codici-libro'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Licenze dal Bookshop', 'raffaello-codici-libro'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e('Endpoint', 'raffaello-codici-libro'); ?></th>
                        <td><code><?php echo esc_html(rest_url(Push::ROUTE_NAMESPACE . '/eventi')); ?></code></td>
                    </tr>
                    <tr>
                        <th><label for="rcl-segreto"><?php esc_html_e('Segreto', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <?php if ($costante) : ?>
                                <input type="password" id="rcl-segreto" class="regular-text" disabled placeholder="<?php esc_attr_e('Gestito da wp-config.php', 'raffaello-codici-libro'); ?>" />
                            <?php else : ?>
                                <input type="password" id="rcl-segreto" name="segreto" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $config['fonte'] === 'impostazioni' && $config['problema'] === null ? esc_attr__('Salvato: lascia vuoto per non cambiarlo', 'raffaello-codici-libro') : ''; ?>" />
                                <p class="description"><?php esc_html_e('Lo stesso valore va nelle impostazioni del plugin «Raffaello Licenze — Bookshop». Incolla qui un segreto di almeno 32 caratteri senza spazi, oppure generalo con il pulsante qui sotto. Lasciato vuoto non cambia; il segreto salvato non si rilegge.', 'raffaello-codici-libro'); ?></p>
                            <?php endif; ?>
                            <p class="description"><?php /* translators: %s: fonte del segreto */ echo esc_html(sprintf(__('Fonte: %s.', 'raffaello-codici-libro'), $fonti[$config['fonte']])); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if (!$costante) : ?>
                <form method="post" action="<?php echo esc_url(self::url('rcl_impostazioni')); ?>" class="rcl-azione-segreto">
                    <?php wp_nonce_field('rcl_genera_segreto'); ?>
                    <input type="hidden" name="rcl_admin_action" value="genera_segreto" />
                    <?php submit_button(__('Genera segreto', 'raffaello-codici-libro'), 'secondary', 'submit', false, $config['fonte'] === '' ? [] : ['onclick' => "return confirm('" . esc_js(__('Il segreto attuale smette di valere: andrà aggiornato anche sul Bookshop. Continuare?', 'raffaello-codici-libro')) . "');"]); ?>
                </form>
                <?php if ($config['fonte'] !== '') : ?>
                    <form method="post" action="<?php echo esc_url(self::url('rcl_impostazioni')); ?>" class="rcl-azione-segreto">
                        <?php wp_nonce_field('rcl_cancella_segreto'); ?>
                        <input type="hidden" name="rcl_admin_action" value="cancella_segreto" />
                        <?php submit_button(__('Cancella segreto', 'raffaello-codici-libro'), 'delete', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Cancellare il segreto? Gli acquisti dal Bookshop verranno rifiutati finché non ne salvi uno nuovo.', 'raffaello-codici-libro')) . "');"]); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <h2><?php esc_html_e('LearnDash', 'raffaello-codici-libro'); ?></h2>
            <p>
                <?php echo LearnDash::active()
                    ? esc_html__('LearnDash è attivo: i corsi collegati alle licenze si iscrivono e disiscrivono da soli.', 'raffaello-codici-libro')
                    : esc_html__('LearnDash non è attivo: i corsi collegati alle licenze vengono ignorati.', 'raffaello-codici-libro'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(self::url('rcl_impostazioni')); ?>">
                <?php wp_nonce_field('rcl_riallinea'); ?>
                <input type="hidden" name="rcl_admin_action" value="riallinea" />
                <p class="description"><?php esc_html_e('Da usare se LearnDash è stato attivato dopo gli acquisti o dopo modifiche fatte fuori dal plugin. Tocca solo le iscrizioni fatte dal plugin.', 'raffaello-codici-libro'); ?></p>
                <?php submit_button(__('Riallinea LearnDash', 'raffaello-codici-libro'), 'secondary', 'submit', false, LearnDash::active() ? [] : ['disabled' => 'disabled']); ?>
            </form>
        </div>
        <?php
    }

    /* ----------------------------------------------------------------------
     * Utilità
     * -------------------------------------------------------------------- */

    private static function url(string $page, array $args = []): string
    {
        return add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'));
    }

    private static function action_url(string $page, string $action, int $id): string
    {
        return wp_nonce_url(self::url($page, ['rcl_admin_action' => $action, 'id' => $id]), 'rcl_' . $action . '_' . $id);
    }

    /**
     * Data di un campo del form (aaaa-mm-gg o gg/mm/aaaa) come fine di quel
     * giorno nel fuso del sito, in UTC. Null se vuota o non valida.
     */
    private static function date_from_input(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m)) {
            $value = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return get_gmt_from_date($value . ' 23:59:59');
    }

    private static function search_limit(bool $cerca, int $total): void
    {
        if ($cerca && $total > self::MAX_RICERCA) {
            /* translators: %d: risultati mostrati */
            echo ' ' . esc_html(sprintf(__('Mostrati i primi %d: restringi la ricerca.', 'raffaello-codici-libro'), self::MAX_RICERCA));
        }
    }

    private static function pagination(int $total, int $paged): void
    {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages <= 1) {
            return;
        }
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'current' => $paged,
            'total'   => $pages,
        ]);
        echo '</div></div>';
    }

    private function notice(): void
    {
        if (empty($_GET['rcl_msg'])) {
            return;
        }
        $msg = sanitize_key($_GET['rcl_msg']);
        $ins = isset($_GET['ins']) ? (int) $_GET['ins'] : 0;
        $skip = isset($_GET['skip']) ? (int) $_GET['skip'] : 0;
        $n = isset($_GET['n']) ? (int) $_GET['n'] : 0;
        $ko = isset($_GET['ko']) ? (int) $_GET['ko'] : 0;
        $map = [
            'licenza_salvata'   => ['success', __('Licenza salvata.', 'raffaello-codici-libro') . ($ins || $skip ? ' ' . sprintf(_n('Codici stampati: %d aggiunto,', 'Codici stampati: %d aggiunti,', $ins, 'raffaello-codici-libro'), $ins)
                . ' ' . sprintf(_n('%d scartato (già di un\'altra licenza, acquistato o troppo lungo).', '%d scartati (già di un\'altra licenza, acquistati o troppo lunghi).', $skip, 'raffaello-codici-libro'), $skip) : '')],
            'licenza_eliminata' => ['success', __('Licenza eliminata.', 'raffaello-codici-libro')],
            'licenza_in_uso'    => ['error', __('La licenza ha codici o attivazioni: disattivala invece di eliminarla.', 'raffaello-codici-libro')],
            'nome_vuoto'        => ['error', __('Il nome è obbligatorio.', 'raffaello-codici-libro')],
            'sku_duplicato'     => ['error', __('Lo SKU è già usato da un\'altra licenza.', 'raffaello-codici-libro')],
            'codice_salvato'    => ['success', __('Codice salvato.', 'raffaello-codici-libro')],
            'codice_eliminato'  => ['success', __('Codice eliminato.', 'raffaello-codici-libro')],
            'codice_in_uso'     => ['error', __('Si eliminano solo i codici stampati mai riscattati: disattivalo invece.', 'raffaello-codici-libro')],
            'codice_duplicato'  => ['error', __('Codice già presente.', 'raffaello-codici-libro')],
            'codice_non_valido' => ['error', __('Il codice è vuoto o più lungo di 64 caratteri.', 'raffaello-codici-libro')],
            'file_non_valido'   => ['error', __('File CSV mancante, non leggibile o oltre 2 MB.', 'raffaello-codici-libro')],
            'import_ok'         => ['success', sprintf(_n('Import completato: %d inserito,', 'Import completato: %d inseriti,', $ins, 'raffaello-codici-libro'), $ins)
                . ' ' . sprintf(_n('%d scartato.', '%d scartati.', $skip, 'raffaello-codici-libro'), $skip)],
            'utente_o_licenza'  => ['error', __('Utente o licenza non trovati.', 'raffaello-codici-libro')],
            'grant_creata'      => ['success', __('Licenza attivata per l\'utente.', 'raffaello-codici-libro')],
            'grant_esistente'   => ['warning', __('L\'utente ha già un\'attivazione manuale di questa licenza.', 'raffaello-codici-libro')],
            'grant_revocata'    => ['warning', __('L\'utente ha un\'attivazione manuale revocata di questa licenza: usa "Riattiva" nell\'elenco.', 'raffaello-codici-libro')],
            'revocata'          => ['success', __('Attivazione revocata.', 'raffaello-codici-libro')],
            'riattivata'        => ['success', __('Attivazione riattivata.', 'raffaello-codici-libro')],
            'non_modificata'    => ['error', __('Attivazione non trovata o già revocata.', 'raffaello-codici-libro')],
            'non_riattivabile'  => ['error', __('Attivazione non riattivabile: il suo codice è stato revocato dal Bookshop.', 'raffaello-codici-libro')],
            'impostazioni_salvate' => ['success', __('Impostazioni salvate.', 'raffaello-codici-libro')],
            'segreto_salvato'   => ['success', __('Impostazioni e segreto del Bookshop salvati.', 'raffaello-codici-libro')],
            'segreto_generato'  => ['success', __('Segreto del Bookshop generato e salvato.', 'raffaello-codici-libro')],
            'segreto_cancellato' => ['warning', __('Segreto del Bookshop cancellato: gli acquisti vengono rifiutati finché non ne salvi uno nuovo.', 'raffaello-codici-libro')],
            'segreto_non_valido' => ['error', __('Non è stato salvato niente: il segreto deve avere almeno 32 caratteri, senza spazi.', 'raffaello-codici-libro')],
            'segreto_non_salvato' => ['error', __('Segreto non salvato: riprova.', 'raffaello-codici-libro')],
            'segreto_non_cancellato' => ['error', __('Segreto non cancellato: riprova.', 'raffaello-codici-libro')],
            'segreto_non_mostrato' => ['error', __('Segreto generato e salvato, ma non è stato possibile mostrarlo: generane un altro.', 'raffaello-codici-libro')],
            'riallineato'       => [$ko ? 'warning' : 'success', sprintf(_n('LearnDash riallineato per %d utente.', 'LearnDash riallineato per %d utenti.', $n, 'raffaello-codici-libro'), $n)
                . ($ko ? ' ' . sprintf(_n('Non riuscito per %d utente (dettagli nel log), riprova.', 'Non riuscito per %d utenti (dettagli nel log), riprova.', $ko, 'raffaello-codici-libro'), $ko) : '')],
        ];
        if (isset($map[$msg])) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($map[$msg][0]), esc_html($map[$msg][1]));
        }
    }
}
