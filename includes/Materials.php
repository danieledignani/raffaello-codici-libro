<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestione dei materiali scaricabili associati a un contenuto.
 *
 * I materiali sono salvati come post meta `_rcl_materiali`: un array di voci
 * { id, titolo, attachment_id }. Il meta box è disponibile su pagine e articoli.
 */
class Materials
{
    const META_KEY = '_rcl_materiali';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
    }

    /** Tipi di contenuto su cui mostrare il meta box. */
    private function post_types(): array
    {
        return apply_filters('rcl_materiali_post_types', ['page', 'post']);
    }

    public function add_meta_box(): void
    {
        foreach ($this->post_types() as $type) {
            add_meta_box(
                'rcl_materiali',
                __('Materiali sbloccabili (Codici Libro)', 'raffaello-codici-libro'),
                [$this, 'render_meta_box'],
                $type,
                'normal',
                'default'
            );
        }
    }

    public function enqueue_admin($hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'rcl-admin-materiali',
            RCL_PLUGIN_URL . 'assets/js/admin-materiali.js',
            ['jquery'],
            RCL_VERSION,
            true
        );
        wp_enqueue_style(
            'rcl-admin',
            RCL_PLUGIN_URL . 'assets/css/admin.css',
            [],
            RCL_VERSION
        );
    }

    public function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('rcl_save_materiali', 'rcl_materiali_nonce');
        $materiali = self::get_materials($post->ID);
        ?>
        <p class="description">
            <?php esc_html_e('Elenco dei materiali scaricabili sbloccabili con il codice del libro. I visitatori vedranno titoli e stato "bloccato" finché non inseriscono un codice valido.', 'raffaello-codici-libro'); ?>
        </p>
        <table class="widefat rcl-materiali-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Titolo', 'raffaello-codici-libro'); ?></th>
                    <th><?php esc_html_e('File', 'raffaello-codici-libro'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="rcl-materiali-rows">
                <?php foreach ($materiali as $m) : ?>
                    <tr class="rcl-materiale-row">
                        <td><input type="text" name="rcl_materiali_titolo[]" value="<?php echo esc_attr($m['titolo']); ?>" class="widefat" /></td>
                        <td>
                            <input type="hidden" name="rcl_materiali_attachment[]" value="<?php echo esc_attr($m['attachment_id']); ?>" class="rcl-attachment-id" />
                            <span class="rcl-attachment-name"><?php echo esc_html(self::attachment_name((int) $m['attachment_id'])); ?></span>
                            <button type="button" class="button rcl-select-file"><?php esc_html_e('Scegli file', 'raffaello-codici-libro'); ?></button>
                        </td>
                        <td><button type="button" class="button rcl-remove-row">&times;</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" class="button button-secondary" id="rcl-add-row"><?php esc_html_e('+ Aggiungi materiale', 'raffaello-codici-libro'); ?></button>
        </p>

        <script type="text/template" id="rcl-materiale-template">
            <tr class="rcl-materiale-row">
                <td><input type="text" name="rcl_materiali_titolo[]" value="" class="widefat" /></td>
                <td>
                    <input type="hidden" name="rcl_materiali_attachment[]" value="" class="rcl-attachment-id" />
                    <span class="rcl-attachment-name"></span>
                    <button type="button" class="button rcl-select-file"><?php esc_html_e('Scegli file', 'raffaello-codici-libro'); ?></button>
                </td>
                <td><button type="button" class="button rcl-remove-row">&times;</button></td>
            </tr>
        </script>
        <?php
    }

    public function save(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['rcl_materiali_nonce']) || !wp_verify_nonce(wp_unslash($_POST['rcl_materiali_nonce']), 'rcl_save_materiali')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $titoli = isset($_POST['rcl_materiali_titolo']) ? (array) wp_unslash($_POST['rcl_materiali_titolo']) : [];
        $attachments = isset($_POST['rcl_materiali_attachment']) ? (array) wp_unslash($_POST['rcl_materiali_attachment']) : [];

        $materiali = [];
        foreach ($titoli as $i => $titolo) {
            $titolo = sanitize_text_field($titolo);
            $attachment_id = isset($attachments[$i]) ? (int) $attachments[$i] : 0;
            if ($titolo === '' && $attachment_id === 0) {
                continue;
            }
            $materiali[] = [
                'id'            => 'm' . ($i + 1) . '_' . $attachment_id,
                'titolo'        => $titolo !== '' ? $titolo : self::attachment_name($attachment_id),
                'attachment_id' => $attachment_id,
            ];
        }

        if (empty($materiali)) {
            delete_post_meta($post_id, self::META_KEY);
        } else {
            update_post_meta($post_id, self::META_KEY, $materiali);
        }
    }

    /** Restituisce i materiali di un contenuto. */
    public static function get_materials(int $post_id): array
    {
        $materiali = get_post_meta($post_id, self::META_KEY, true);
        if (!is_array($materiali)) {
            return [];
        }
        return $materiali;
    }

    /** Nome leggibile di un allegato. */
    public static function attachment_name(int $attachment_id): string
    {
        if ($attachment_id <= 0) {
            return '';
        }
        $file = get_attached_file($attachment_id);
        return $file ? basename($file) : '';
    }
}
