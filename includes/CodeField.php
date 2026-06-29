<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campo per-pagina per indicare quali codici sbloccano il contenuto.
 *
 * Punto di ingresso "lato pagina": l'editor digita direttamente sulla pagina i
 * codici del libro che la sbloccano (come si aspetta chi lavora con ACF/YOOtheme).
 * Se ACF è attivo viene registrato un field group via PHP; altrimenti si usa un
 * meta box nativo equivalente. In entrambi i casi i codici vengono sincronizzati
 * con la tabella delle associazioni (rcl_codice_post), creando al volo i codici
 * non ancora presenti.
 *
 * Il valore mostrato riflette SEMPRE lo stato reale delle associazioni, così
 * resta coerente anche con i legami creati dal menu centrale "Codici Libro".
 */
class CodeField
{
    const META_KEY = 'rcl_codici_sblocco';
    const META_BLOCK = 'rcl_blocca_pagina';

    public function register(): void
    {
        if (function_exists('acf_add_local_field_group')) {
            add_action('acf/init', [$this, 'register_acf']);
            add_filter('acf/load_value/name=' . self::META_KEY, [$this, 'acf_load_value'], 10, 3);
            add_action('acf/save_post', [$this, 'acf_save'], 20);
            add_filter('acf/prepare_field/key=field_rcl_codici_status', [$this, 'acf_prepare_status']);
        } else {
            add_action('add_meta_boxes', [$this, 'add_meta_box']);
            add_action('save_post', [$this, 'save_native'], 20, 2);
        }
    }

    /** Tipi di contenuto su cui mostrare il campo. */
    private function post_types(): array
    {
        return apply_filters('rcl_post_types', ['page', 'post']);
    }

    /* ----------------------------------------------------------------------
     * Versione ACF
     * -------------------------------------------------------------------- */

    public function register_acf(): void
    {
        $location = [];
        foreach ($this->post_types() as $type) {
            $location[] = [[
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => $type,
            ]];
        }

        acf_add_local_field_group([
            'key'      => 'group_rcl_codici_sblocco',
            'title'    => __('Codici Libro — Sblocco', 'raffaello-codici-libro'),
            'fields'   => [[
                'key'          => 'field_rcl_codici_sblocco',
                'label'        => __('Codici che sbloccano questa pagina', 'raffaello-codici-libro'),
                'name'         => self::META_KEY,
                'type'         => 'textarea',
                'rows'         => 4,
                'new_lines'    => '',
                'instructions' => __('Un codice del libro per riga (o separati da virgola). Chi riscatta uno di questi codici sblocca i materiali della pagina. I codici non ancora presenti vengono creati automaticamente.', 'raffaello-codici-libro'),
            ], [
                // Riepilogo (sola lettura) dei codici associati con stato:
                // popolato dinamicamente in acf_prepare_status().
                'key'       => 'field_rcl_codici_status',
                'label'     => '',
                'name'      => '',
                'type'      => 'message',
                'message'   => '',
                'esc_html'  => 0,
                'new_lines' => '',
            ], [
                'key'          => 'field_rcl_blocca_pagina',
                'label'        => __('Blocca l\'intera pagina con il codice', 'raffaello-codici-libro'),
                'name'         => self::META_BLOCK,
                'type'         => 'true_false',
                'ui'           => 1,
                'instructions' => __('Se attivo, l\'intera pagina è accessibile solo a chi ha riscattato uno dei codici qui sopra; agli altri viene mostrato il form di sblocco al posto del contenuto.', 'raffaello-codici-libro'),
            ]],
            'location'   => $location,
            'menu_order' => 0,
            'position'   => 'side',
            'style'      => 'default',
        ]);
    }

    /** Mostra sempre i codici realmente collegati alla pagina (uno per riga). */
    public function acf_load_value($value, $post_id, $field)
    {
        $codici = Database::get_post_codes((int) $post_id);
        if (empty($codici)) {
            return '';
        }
        return implode("\n", wp_list_pluck($codici, 'codice'));
    }

    public function acf_save($post_id): void
    {
        $post_id = (int) $post_id;
        if (!in_array(get_post_type($post_id), $this->post_types(), true)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        // Valore grezzo appena salvato da ACF (post meta con il nome del campo).
        // NON usiamo get_field(): il filtro acf/load_value sostituisce sempre il
        // valore con le associazioni già presenti nel DB, facendo perdere quanto
        // digitato dall'editor. La nostra action è a priorità 20, quindi gira
        // dopo che ACF (priorità 10) ha salvato i valori nel meta.
        $raw = (string) get_post_meta($post_id, self::META_KEY, true);
        $this->sync_codes($post_id, $raw);
    }

    /** Popola dinamicamente il campo "message" col riepilogo dei codici associati. */
    public function acf_prepare_status($field)
    {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $field['message'] = $post_id > 0 ? $this->codes_status_html($post_id) : '';
        return $field;
    }

    /* ----------------------------------------------------------------------
     * Riepilogo stato dei codici associati (sola lettura)
     * -------------------------------------------------------------------- */

    /** HTML della lista dei codici associati alla pagina con il loro stato. */
    private function codes_status_html(int $post_id): string
    {
        $codes = Database::get_post_codes_full($post_id);
        if (empty($codes)) {
            return '<p class="description" style="margin:6px 0 0;">'
                . esc_html__('Nessun codice ancora associato a questa pagina.', 'raffaello-codici-libro')
                . '</p>';
        }

        $out  = '<p class="description" style="margin:8px 0 4px;">' . esc_html__('Codici associati:', 'raffaello-codici-libro') . '</p>';
        $out .= '<ul class="rcl-codici-stato" style="margin:0; padding:0; list-style:none;">';
        foreach ($codes as $c) {
            list($label, $color) = $this->code_status($c);
            $edit_url = add_query_arg(
                ['page' => 'rcl_codici', 'view' => 'edit', 'id' => (int) $c->id],
                admin_url('admin.php')
            );
            $out .= '<li style="margin:3px 0; font-size:12px; line-height:1.5;">'
                . '<code>' . esc_html($c->codice) . '</code> '
                . '<span style="color:' . esc_attr($color) . '; font-weight:600;">' . esc_html($label) . '</span> '
                . '<a href="' . esc_url($edit_url) . '" target="_blank" rel="noopener" style="text-decoration:none;">' . esc_html__('gestisci', 'raffaello-codici-libro') . ' &rsaquo;</a>'
                . '</li>';
        }
        $out .= '</ul>';
        return $out;
    }

    /** Etichetta + colore dello stato di un codice. */
    private function code_status($code): array
    {
        if ((int) $code->attivo !== 1) {
            return [__('Disattivo', 'raffaello-codici-libro'), '#b26a00'];
        }
        if (!empty($code->data_scadenza) && strtotime($code->data_scadenza) < current_time('timestamp')) {
            return [__('Scaduto', 'raffaello-codici-libro'), '#c62828'];
        }
        return [__('Attivo', 'raffaello-codici-libro'), '#2e7d32'];
    }

    /* ----------------------------------------------------------------------
     * Versione meta box nativa (fallback senza ACF)
     * -------------------------------------------------------------------- */

    public function add_meta_box(): void
    {
        foreach ($this->post_types() as $type) {
            add_meta_box(
                'rcl_codici_sblocco',
                __('Codici Libro — Sblocco', 'raffaello-codici-libro'),
                [$this, 'render_meta_box'],
                $type,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('rcl_save_codici', 'rcl_codici_nonce');
        $codici = Database::get_post_codes($post->ID);
        $value = implode("\n", wp_list_pluck($codici, 'codice'));
        ?>
        <p class="description">
            <?php esc_html_e('Un codice del libro per riga. Chi riscatta uno di questi codici sblocca i materiali della pagina. I codici non ancora presenti vengono creati automaticamente.', 'raffaello-codici-libro'); ?>
        </p>
        <textarea name="<?php echo esc_attr(self::META_KEY); ?>" rows="4" style="width:100%;"><?php echo esc_textarea($value); ?></textarea>
        <?php echo $this->codes_status_html($post->ID); ?>
        <p style="margin-top:10px;">
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::META_BLOCK); ?>" value="1" <?php checked(get_post_meta($post->ID, self::META_BLOCK, true), '1'); ?> />
                <?php esc_html_e('Blocca l\'intera pagina con il codice', 'raffaello-codici-libro'); ?>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('Se attivo, l\'intera pagina è accessibile solo a chi ha riscattato uno dei codici; agli altri viene mostrato il form di sblocco al posto del contenuto.', 'raffaello-codici-libro'); ?>
        </p>
        <?php
    }

    public function save_native(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['rcl_codici_nonce']) || !wp_verify_nonce(wp_unslash($_POST['rcl_codici_nonce']), 'rcl_save_codici')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $raw = isset($_POST[self::META_KEY]) ? (string) wp_unslash($_POST[self::META_KEY]) : '';
        $this->sync_codes($post_id, $raw);

        update_post_meta($post_id, self::META_BLOCK, empty($_POST[self::META_BLOCK]) ? '0' : '1');
    }

    /* ----------------------------------------------------------------------
     * Sincronizzazione
     * -------------------------------------------------------------------- */

    /** Converte il testo inserito in associazioni codice↔pagina. */
    private function sync_codes(int $post_id, string $raw): void
    {
        $tokens = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $code_ids = [];
        foreach ($tokens as $token) {
            $norm = Database::normalize_code($token);
            if ($norm === '') {
                continue;
            }
            $id = Database::find_or_create_code($norm);
            if ($id > 0) {
                $code_ids[] = $id;
            }
        }
        Database::set_post_codes($post_id, $code_ids);
    }
}
