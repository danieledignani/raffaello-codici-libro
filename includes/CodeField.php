<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Riquadro della pagina: licenze che la sbloccano e interruttore del blocco
 * dell'intera pagina. Con ACF è un field group registrato via PHP, altrimenti un
 * meta box nativo equivalente.
 *
 * Le licenze selezionate non si salvano nei meta della pagina: si scrivono e si
 * leggono da rcl_licenza_post, l'unica fonte delle associazioni.
 */
class CodeField
{
    const META_KEY = 'rcl_licenze_sblocco';
    const META_BLOCK = 'rcl_blocca_pagina';

    public function register(): void
    {
        if (function_exists('acf_add_local_field_group')) {
            add_action('acf/init', [$this, 'register_acf']);
            add_filter('acf/load_field/key=field_rcl_licenze', [$this, 'acf_load_field']);
            add_filter('acf/load_value/key=field_rcl_licenze', [$this, 'acf_load_value'], 10, 2);
            add_filter('acf/pre_update_value', [$this, 'acf_pre_update_value'], 10, 4);
            add_filter('acf/prepare_field/key=field_rcl_avviso', [$this, 'acf_prepare_notice']);
        } else {
            add_action('add_meta_boxes', [$this, 'add_meta_box']);
            add_action('save_post', [$this, 'save_native'], 20, 2);
        }
    }

    /** Tipi di contenuto su cui mostrare il riquadro. */
    private function post_types(): array
    {
        return apply_filters('rcl_post_types', ['page', 'post']);
    }

    /** Licenze selezionabili: id => nome, segnando quelle disattivate. */
    private static function choices(): array
    {
        $choices = [];
        foreach (Licenses::all() as $l) {
            $choices[(string) $l->id] = $l->nome . ((int) $l->attiva ? '' : ' ' . __('(disattivata)', 'raffaello-codici-libro'));
        }
        return $choices;
    }

    /** Avviso: pagina bloccata senza licenze, quindi visibile solo agli amministratori. */
    private static function notice_html(int $post_id): string
    {
        if (!get_post_meta($post_id, self::META_BLOCK, true) || Licenses::of_post($post_id)) {
            return '';
        }
        return '<p style="margin:6px 0;padding:6px 8px;border-left:4px solid #d63638;background:#fcf0f1;">'
            . esc_html__('Pagina bloccata ma nessuna licenza la sblocca: la vedono solo gli amministratori.', 'raffaello-codici-libro')
            . '</p>';
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
            'title'    => __('Raffaello Licenze', 'raffaello-codici-libro'),
            'fields'   => [[
                'key'          => 'field_rcl_licenze',
                'label'        => __('Licenze che sbloccano questa pagina', 'raffaello-codici-libro'),
                'name'         => self::META_KEY,
                'type'         => 'select',
                'multiple'     => 1,
                'ui'           => 1,
                'allow_null'   => 1,
                'choices'      => [],
                'instructions' => __('Chi ha una di queste licenze attiva sblocca i materiali della pagina.', 'raffaello-codici-libro'),
            ], [
                // Avviso per la pagina bloccata senza licenze: popolato in acf_prepare_notice().
                'key'       => 'field_rcl_avviso',
                'label'     => '',
                'name'      => '',
                'type'      => 'message',
                'message'   => '',
                'esc_html'  => 0,
                'new_lines' => '',
            ], [
                'key'          => 'field_rcl_blocca_pagina',
                'label'        => __('Blocca l\'intera pagina', 'raffaello-codici-libro'),
                'name'         => self::META_BLOCK,
                'type'         => 'true_false',
                'ui'           => 1,
                'instructions' => __('Se attivo, la pagina è accessibile solo a chi ha una delle licenze qui sopra; agli altri viene mostrato il form di sblocco al posto del contenuto. Senza licenze la vedono solo gli amministratori.', 'raffaello-codici-libro'),
            ]],
            'location'   => $location,
            'menu_order' => 0,
            'position'   => 'side',
            'style'      => 'default',
        ]);
    }

    public function acf_load_field($field)
    {
        $field['choices'] = self::choices();
        return $field;
    }

    /** Mostra sempre le licenze realmente collegate alla pagina. */
    public function acf_load_value($value, $post_id)
    {
        return is_numeric($post_id) ? array_map('strval', Licenses::of_post((int) $post_id)) : [];
    }

    /** Salva le licenze in rcl_licenza_post al posto del meta (che non si scrive). */
    public function acf_pre_update_value($check, $value, $post_id, $field)
    {
        if (($field['key'] ?? '') !== 'field_rcl_licenze') {
            return $check;
        }
        if (is_numeric($post_id) && !wp_is_post_revision((int) $post_id) && current_user_can('edit_post', (int) $post_id)) {
            Licenses::set_post_licenses((int) $post_id, (array) $value);
        }
        return true;
    }

    public function acf_prepare_notice($field)
    {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $field['message'] = $post_id > 0 ? self::notice_html($post_id) : '';
        return $field['message'] !== '' ? $field : false;
    }

    /* ----------------------------------------------------------------------
     * Versione meta box nativa (fallback senza ACF)
     * -------------------------------------------------------------------- */

    public function add_meta_box(): void
    {
        foreach ($this->post_types() as $type) {
            add_meta_box(
                'rcl_codici_sblocco',
                __('Raffaello Licenze', 'raffaello-codici-libro'),
                [$this, 'render_meta_box'],
                $type,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('rcl_save_licenze', 'rcl_licenze_nonce');
        $selected = Licenses::of_post($post->ID);
        echo self::notice_html($post->ID);
        ?>
        <p><label for="rcl-licenze"><strong><?php esc_html_e('Licenze che sbloccano questa pagina', 'raffaello-codici-libro'); ?></strong></label></p>
        <select id="rcl-licenze" name="rcl_licenze[]" multiple size="6" style="width:100%;">
            <?php foreach (self::choices() as $id => $nome) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected(in_array((int) $id, $selected, true)); ?>><?php echo esc_html($nome); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('Tieni premuto CTRL/CMD per selezionarne più di una.', 'raffaello-codici-libro'); ?></p>
        <p style="margin-top:10px;">
            <label>
                <input type="checkbox" name="<?php echo esc_attr(self::META_BLOCK); ?>" value="1" <?php checked(get_post_meta($post->ID, self::META_BLOCK, true), '1'); ?> />
                <?php esc_html_e('Blocca l\'intera pagina', 'raffaello-codici-libro'); ?>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('Se attivo, la pagina è accessibile solo a chi ha una delle licenze; agli altri viene mostrato il form di sblocco al posto del contenuto. Senza licenze la vedono solo gli amministratori.', 'raffaello-codici-libro'); ?>
        </p>
        <?php
    }

    public function save_native(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['rcl_licenze_nonce']) || !wp_verify_nonce(wp_unslash($_POST['rcl_licenze_nonce']), 'rcl_save_licenze')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        Licenses::set_post_licenses($post_id, isset($_POST['rcl_licenze']) ? (array) wp_unslash($_POST['rcl_licenze']) : []);
        update_post_meta($post_id, self::META_BLOCK, empty($_POST[self::META_BLOCK]) ? '0' : '1');
    }
}
