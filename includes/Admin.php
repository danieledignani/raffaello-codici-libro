<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backoffice: gestione codici, associazione ai contenuti, import CSV ed
 * elenco dei riscatti.
 */
class Admin
{
    const CAP = 'manage_options';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('Codici Libro', 'raffaello-codici-libro'),
            __('Codici Libro', 'raffaello-codici-libro'),
            self::CAP,
            'rcl_codici',
            [$this, 'page_codici'],
            'dashicons-unlock',
            56
        );
        add_submenu_page('rcl_codici', __('Codici', 'raffaello-codici-libro'), __('Codici', 'raffaello-codici-libro'), self::CAP, 'rcl_codici', [$this, 'page_codici']);
        add_submenu_page('rcl_codici', __('Importa CSV', 'raffaello-codici-libro'), __('Importa CSV', 'raffaello-codici-libro'), self::CAP, 'rcl_import', [$this, 'page_import']);
        add_submenu_page('rcl_codici', __('Riscatti', 'raffaello-codici-libro'), __('Riscatti', 'raffaello-codici-libro'), self::CAP, 'rcl_riscatti', [$this, 'page_riscatti']);
    }

    public function enqueue($hook): void
    {
        if (strpos((string) $hook, 'rcl_') === false) {
            return;
        }
        wp_enqueue_style('rcl-admin', RCL_PLUGIN_URL . 'assets/css/admin.css', [], RCL_VERSION);
    }

    /* ----------------------------------------------------------------------
     * Gestione azioni (salvataggio, eliminazione, import)
     * -------------------------------------------------------------------- */

    public function handle_actions(): void
    {
        if (!isset($_POST['rcl_admin_action']) && !isset($_GET['rcl_admin_action'])) {
            return;
        }
        if (!current_user_can(self::CAP)) {
            return;
        }

        $action = isset($_POST['rcl_admin_action'])
            ? sanitize_key($_POST['rcl_admin_action'])
            : sanitize_key($_GET['rcl_admin_action']);

        switch ($action) {
            case 'save_code':
                $this->action_save_code();
                break;
            case 'delete_code':
                $this->action_delete_code();
                break;
            case 'import_csv':
                $this->action_import_csv();
                break;
        }
    }

    private function action_save_code(): void
    {
        check_admin_referer('rcl_save_code');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $codice = isset($_POST['codice']) ? Database::normalize_code(sanitize_text_field(wp_unslash($_POST['codice']))) : '';
        $descrizione = isset($_POST['descrizione']) ? sanitize_text_field(wp_unslash($_POST['descrizione'])) : '';
        $attivo = !empty($_POST['attivo']);
        $scadenza = isset($_POST['data_scadenza']) ? sanitize_text_field(wp_unslash($_POST['data_scadenza'])) : '';
        $scadenza = $scadenza !== '' ? gmdate('Y-m-d H:i:s', strtotime($scadenza)) : null;
        $post_ids = isset($_POST['post_ids']) ? (array) wp_unslash($_POST['post_ids']) : [];

        if ($id > 0) {
            Database::update_code($id, $descrizione ?: null, $attivo, $scadenza);
        } else {
            if ($codice === '') {
                $this->redirect_codici(['rcl_msg' => 'codice_vuoto']);
            }
            $res = Database::insert_code($codice, $descrizione ?: null, $attivo, $scadenza);
            if (is_wp_error($res)) {
                $this->redirect_codici(['rcl_msg' => $res->get_error_code()]);
            }
            $id = (int) $res;
        }

        Database::set_code_posts($id, $post_ids);
        $this->redirect_codici(['rcl_msg' => 'salvato']);
    }

    private function action_delete_code(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('rcl_delete_code_' . $id);
        if ($id > 0) {
            Database::delete_code($id);
        }
        $this->redirect_codici(['rcl_msg' => 'eliminato']);
    }

    private function action_import_csv(): void
    {
        check_admin_referer('rcl_import_csv');

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $this->redirect_import(['rcl_msg' => 'file_mancante']);
        }

        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$handle) {
            $this->redirect_import(['rcl_msg' => 'file_illeggibile']);
        }

        // Rileva il separatore dalla prima riga (";" oppure ",").
        $first = fgets($handle);
        $delimiter = (substr_count($first, ';') >= substr_count($first, ',')) ? ';' : ',';
        rewind($handle);

        $inseriti = 0;
        $saltati = 0;
        $riga = 0;

        while (($cols = fgetcsv($handle, 0, $delimiter)) !== false) {
            $riga++;
            $codice = isset($cols[0]) ? Database::normalize_code((string) $cols[0]) : '';
            // Salta intestazione o righe vuote.
            if ($codice === '' || ($riga === 1 && stripos((string) $cols[0], 'codice') !== false)) {
                continue;
            }

            $descrizione = isset($cols[1]) ? sanitize_text_field($cols[1]) : null;
            $scadenza = !empty($cols[2]) ? gmdate('Y-m-d H:i:s', strtotime($cols[2])) : null;
            $post_ids = !empty($cols[3]) ? array_map('intval', preg_split('/[,\s]+/', trim($cols[3]))) : [];

            $res = Database::insert_code($codice, $descrizione, true, $scadenza);
            if (is_wp_error($res)) {
                $saltati++;
                continue;
            }
            if (!empty($post_ids)) {
                Database::set_code_posts((int) $res, $post_ids);
            }
            $inseriti++;
        }
        fclose($handle);

        $this->redirect_import(['rcl_msg' => 'import_ok', 'ins' => $inseriti, 'skip' => $saltati]);
    }

    private function redirect_codici(array $args): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => 'rcl_codici'], $args), admin_url('admin.php')));
        exit;
    }

    private function redirect_import(array $args): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => 'rcl_import'], $args), admin_url('admin.php')));
        exit;
    }

    /* ----------------------------------------------------------------------
     * Pagine
     * -------------------------------------------------------------------- */

    public function page_codici(): void
    {
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'list';
        if ($view === 'edit' || $view === 'new') {
            $this->render_code_form();
            return;
        }
        $this->render_codes_list();
    }

    private function render_codes_list(): void
    {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $per_page = 50;
        $offset = ($paged - 1) * $per_page;

        $codici = Database::list_codes($per_page, $offset, $search);
        $total = Database::count_codes($search);
        $pages = (int) ceil($total / $per_page);

        $new_url = add_query_arg(['page' => 'rcl_codici', 'view' => 'new'], admin_url('admin.php'));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Codici Libro', 'raffaello-codici-libro'); ?></h1>
            <a href="<?php echo esc_url($new_url); ?>" class="page-title-action"><?php esc_html_e('Aggiungi codice', 'raffaello-codici-libro'); ?></a>
            <?php $this->admin_notice(); ?>

            <form method="get">
                <input type="hidden" name="page" value="rcl_codici" />
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" />
                    <button class="button"><?php esc_html_e('Cerca', 'raffaello-codici-libro'); ?></button>
                </p>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Codice', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Descrizione', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Contenuti', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Stato', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Scadenza', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Riscatti', 'raffaello-codici-libro'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($codici)) : ?>
                    <tr><td colspan="7"><?php esc_html_e('Nessun codice presente.', 'raffaello-codici-libro'); ?></td></tr>
                <?php else : foreach ($codici as $c) :
                    $edit_url = add_query_arg(['page' => 'rcl_codici', 'view' => 'edit', 'id' => $c->id], admin_url('admin.php'));
                    $del_url = wp_nonce_url(add_query_arg(['page' => 'rcl_codici', 'rcl_admin_action' => 'delete_code', 'id' => $c->id], admin_url('admin.php')), 'rcl_delete_code_' . $c->id);
                    $n_contenuti = count(Database::get_code_posts((int) $c->id));
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($c->codice); ?></code></td>
                        <td><?php echo esc_html($c->descrizione); ?></td>
                        <td><?php echo esc_html($n_contenuti); ?></td>
                        <td><?php echo $c->attivo ? esc_html__('Attivo', 'raffaello-codici-libro') : esc_html__('Disattivo', 'raffaello-codici-libro'); ?></td>
                        <td><?php echo $c->data_scadenza ? esc_html(mysql2date('d/m/Y', $c->data_scadenza)) : '—'; ?></td>
                        <td><?php echo esc_html($c->riscatti); ?></td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Modifica', 'raffaello-codici-libro'); ?></a> |
                            <a href="<?php echo esc_url($del_url); ?>" onclick="return confirm('<?php echo esc_js(__('Eliminare il codice e i relativi riscatti?', 'raffaello-codici-libro')); ?>');"><?php esc_html_e('Elimina', 'raffaello-codici-libro'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ($pages > 1) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $pages,
                    ]);
                    ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_code_form(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $code = $id ? Database::get_code($id) : null;
        $selected = $id ? Database::get_code_posts($id) : [];

        $titolo = $code ? __('Modifica codice', 'raffaello-codici-libro') : __('Nuovo codice', 'raffaello-codici-libro');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($titolo); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=rcl_codici')); ?>">
                <?php wp_nonce_field('rcl_save_code'); ?>
                <input type="hidden" name="rcl_admin_action" value="save_code" />
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="rcl-codice"><?php esc_html_e('Codice', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <?php if ($code) : ?>
                                <code><?php echo esc_html($code->codice); ?></code>
                            <?php else : ?>
                                <input type="text" id="rcl-codice" name="codice" class="regular-text" required />
                                <p class="description"><?php esc_html_e('Verrà normalizzato in maiuscolo senza spazi/trattini.', 'raffaello-codici-libro'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="rcl-descr"><?php esc_html_e('Descrizione', 'raffaello-codici-libro'); ?></label></th>
                        <td><input type="text" id="rcl-descr" name="descrizione" class="regular-text" value="<?php echo esc_attr($code->descrizione ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Attivo', 'raffaello-codici-libro'); ?></th>
                        <td><label><input type="checkbox" name="attivo" value="1" <?php checked($code ? (int) $code->attivo : 1, 1); ?> /> <?php esc_html_e('Codice utilizzabile', 'raffaello-codici-libro'); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="rcl-scad"><?php esc_html_e('Data scadenza', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <input type="date" id="rcl-scad" name="data_scadenza" value="<?php echo esc_attr($code && $code->data_scadenza ? mysql2date('Y-m-d', $code->data_scadenza) : ''); ?>" />
                            <p class="description"><?php esc_html_e('Lasciare vuoto per nessuna scadenza.', 'raffaello-codici-libro'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="rcl-contenuti"><?php esc_html_e('Contenuti sbloccati', 'raffaello-codici-libro'); ?></label></th>
                        <td>
                            <select id="rcl-contenuti" name="post_ids[]" multiple size="12" class="regular-text" style="min-width:360px;">
                                <?php foreach ($this->selectable_posts() as $p) : ?>
                                    <option value="<?php echo esc_attr($p->ID); ?>" <?php selected(in_array((int) $p->ID, $selected, true)); ?>>
                                        <?php echo esc_html($p->post_title . ' (#' . $p->ID . ' · ' . $p->post_type . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Tieni premuto CTRL/CMD per selezionare più contenuti. Un contenuto può essere sbloccato anche da più codici.', 'raffaello-codici-libro'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button($code ? __('Aggiorna', 'raffaello-codici-libro') : __('Crea codice', 'raffaello-codici-libro')); ?>
            </form>
        </div>
        <?php
    }

    /** Contenuti selezionabili come destinazione di sblocco. */
    private function selectable_posts(): array
    {
        return get_posts([
            'post_type'   => apply_filters('rcl_materiali_post_types', ['page', 'post']),
            'post_status' => 'publish',
            'numberposts' => 500,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);
    }

    public function page_import(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Importa codici da CSV', 'raffaello-codici-libro'); ?></h1>
            <?php $this->import_notice(); ?>
            <p><?php esc_html_e('Formato colonne: codice; descrizione; data_scadenza (opzionale); post_ids separati da virgola (opzionale). Separatore "," o ";". L\'eventuale riga di intestazione viene ignorata.', 'raffaello-codici-libro'); ?></p>
            <p><code>ABC123;Matematica 1;2027-08-31;12,34</code></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=rcl_import')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('rcl_import_csv'); ?>
                <input type="hidden" name="rcl_admin_action" value="import_csv" />
                <input type="file" name="csv" accept=".csv,text/csv" required />
                <?php submit_button(__('Importa', 'raffaello-codici-libro')); ?>
            </form>
        </div>
        <?php
    }

    public function page_riscatti(): void
    {
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $per_page = 50;
        $offset = ($paged - 1) * $per_page;

        $riscatti = Database::list_redemptions($per_page, $offset);
        $total = Database::count_redemptions();
        $pages = (int) ceil($total / $per_page);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Riscatti', 'raffaello-codici-libro'); ?></h1>
            <p class="description"><?php printf(esc_html__('Totale riscatti registrati: %d', 'raffaello-codici-libro'), $total); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Codice', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Utente', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Email', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('Data', 'raffaello-codici-libro'); ?></th>
                        <th><?php esc_html_e('IP', 'raffaello-codici-libro'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($riscatti)) : ?>
                    <tr><td colspan="5"><?php esc_html_e('Nessun riscatto registrato.', 'raffaello-codici-libro'); ?></td></tr>
                <?php else : foreach ($riscatti as $r) : ?>
                    <tr>
                        <td><code><?php echo esc_html($r->codice); ?></code></td>
                        <td><?php echo esc_html($r->user_login ?: ('#' . $r->user_id)); ?></td>
                        <td><?php echo esc_html($r->user_email); ?></td>
                        <td><?php echo esc_html(mysql2date('d/m/Y H:i', $r->data_riscatto)); ?></td>
                        <td><?php echo esc_html($r->ip); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ($pages > 1) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $pages,
                    ]);
                    ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ----------------------------------------------------------------------
     * Notice
     * -------------------------------------------------------------------- */

    private function admin_notice(): void
    {
        if (empty($_GET['rcl_msg'])) {
            return;
        }
        $msg = sanitize_key($_GET['rcl_msg']);
        $map = [
            'salvato'          => ['updated', __('Codice salvato.', 'raffaello-codici-libro')],
            'eliminato'        => ['updated', __('Codice eliminato.', 'raffaello-codici-libro')],
            'codice_duplicato' => ['error', __('Codice già presente.', 'raffaello-codici-libro')],
            'codice_vuoto'     => ['error', __('Il codice non può essere vuoto.', 'raffaello-codici-libro')],
            'insert_fallito'   => ['error', __('Inserimento non riuscito.', 'raffaello-codici-libro')],
        ];
        if (isset($map[$msg])) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($map[$msg][0] === 'error' ? 'error' : 'success'), esc_html($map[$msg][1]));
        }
    }

    private function import_notice(): void
    {
        if (empty($_GET['rcl_msg'])) {
            return;
        }
        $msg = sanitize_key($_GET['rcl_msg']);
        if ($msg === 'import_ok') {
            $ins = isset($_GET['ins']) ? (int) $_GET['ins'] : 0;
            $skip = isset($_GET['skip']) ? (int) $_GET['skip'] : 0;
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(sprintf(__('Import completato: %d inseriti, %d saltati (duplicati o non validi).', 'raffaello-codici-libro'), $ins, $skip)));
        } elseif (in_array($msg, ['file_mancante', 'file_illeggibile'], true)) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__('File CSV mancante o non leggibile.', 'raffaello-codici-libro'));
        }
    }
}
