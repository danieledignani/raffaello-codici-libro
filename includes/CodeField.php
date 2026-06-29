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

    public function register(): void
    {
        if (function_exists('acf_add_local_field_group')) {
            add_action('acf/init', [$this, 'register_acf']);
            add_filter('acf/load_value/name=' . self::META_KEY, [$this, 'acf_load_value'], 10, 3);
            add_action('acf/save_post', [$this, 'acf_save'], 20);
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
        $raw = function_exists('get_field') ? (string) get_field(self::META_KEY, $post_id) : '';
        $this->sync_codes($post_id, $raw);
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
