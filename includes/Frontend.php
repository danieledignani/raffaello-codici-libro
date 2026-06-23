<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend: shortcode dei materiali, form di sblocco contestuale, gestione
 * del riscatto (AJAX con fallback senza JavaScript) ed enqueue degli asset.
 */
class Frontend
{
    public function register(): void
    {
        add_shortcode('raffaello_materiali', [$this, 'shortcode_materiali']);
        add_shortcode('raffaello_codice', [$this, 'shortcode_form']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue']);

        // Riscatto: AJAX + fallback POST classico.
        add_action('wp_ajax_rcl_redeem', [$this, 'ajax_redeem']);
        add_action('admin_post_rcl_redeem', [$this, 'post_redeem']);
        add_action('admin_post_nopriv_rcl_redeem', [$this, 'post_redeem']);
    }

    public function enqueue(): void
    {
        wp_enqueue_style('rcl-frontend', RCL_PLUGIN_URL . 'assets/css/frontend.css', [], RCL_VERSION);
        wp_enqueue_script('rcl-frontend', RCL_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], RCL_VERSION, true);
        wp_localize_script('rcl-frontend', 'rclData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('rcl_redeem'),
            'i18n'    => [
                'error' => __('Si è verificato un errore. Riprova.', 'raffaello-codici-libro'),
            ],
        ]);
    }

    /**
     * Shortcode [raffaello_materiali post_id="123"].
     * Mostra i materiali del contenuto: sbloccati (con link di download) oppure
     * bloccati (anteprima + form di sblocco contestuale).
     */
    public function shortcode_materiali($atts): string
    {
        $atts = shortcode_atts(['post_id' => 0], $atts, 'raffaello_materiali');
        $post_id = (int) $atts['post_id'] ?: get_the_ID();
        if (!$post_id) {
            return '';
        }

        $materiali = Materials::get_materials($post_id);
        if (empty($materiali)) {
            return '';
        }

        $user_id = get_current_user_id();
        $has_access = $user_id && Codes::user_can_access($user_id, $post_id);

        ob_start();
        echo '<div class="rcl-materiali" data-post="' . esc_attr($post_id) . '">';

        if ($has_access) {
            $this->render_unlocked($post_id, $materiali);
        } else {
            $this->render_locked($post_id, $materiali);
        }

        echo '</div>';
        return ob_get_clean();
    }

    /** Shortcode [raffaello_codice] — solo il form di inserimento (es. area riservata). */
    public function shortcode_form($atts): string
    {
        $atts = shortcode_atts(['post_id' => 0], $atts, 'raffaello_codice');
        $post_id = (int) $atts['post_id'] ?: get_the_ID();

        ob_start();
        echo '<div class="rcl-materiali">';
        $this->render_form((int) $post_id);
        echo '</div>';
        return ob_get_clean();
    }

    /** Elenco materiali sbloccati con link di download protetti. */
    private function render_unlocked(int $post_id, array $materiali): void
    {
        echo '<ul class="rcl-lista rcl-lista--sbloccata">';
        foreach ($materiali as $m) {
            $attachment_id = (int) $m['attachment_id'];
            if ($attachment_id <= 0) {
                continue;
            }
            $url = Download::url($post_id, $attachment_id);
            echo '<li class="rcl-materiale rcl-materiale--sbloccato">';
            echo '<span class="rcl-icona rcl-icona--ok" aria-hidden="true"></span>';
            echo '<a href="' . esc_url($url) . '" rel="nofollow">' . esc_html($m['titolo']) . '</a>';
            echo '</li>';
        }
        echo '</ul>';
    }

    /** Anteprima dei materiali bloccati + form di sblocco contestuale. */
    private function render_locked(int $post_id, array $materiali): void
    {
        echo '<ul class="rcl-lista rcl-lista--bloccata">';
        foreach ($materiali as $m) {
            echo '<li class="rcl-materiale rcl-materiale--bloccato">';
            echo '<span class="rcl-icona rcl-icona--lock" aria-hidden="true"></span>';
            echo '<span class="rcl-titolo">' . esc_html($m['titolo']) . '</span>';
            echo '<span class="rcl-badge">' . esc_html__('Bloccato', 'raffaello-codici-libro') . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        $this->render_form($post_id);
    }

    /** Form di inserimento codice. */
    private function render_form(int $post_id): void
    {
        if (!is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink($post_id) ?: home_url('/'));
            echo '<div class="rcl-login-invito">';
            echo '<p>' . esc_html__('Accedi per sbloccare i materiali con il codice del tuo libro.', 'raffaello-codici-libro') . '</p>';
            echo '<a class="rcl-button" href="' . esc_url($login_url) . '">' . esc_html__('Accedi', 'raffaello-codici-libro') . '</a>';
            echo '</div>';
            return;
        }

        $this->render_esito();
        ?>
        <form class="rcl-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="rcl_redeem" />
            <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>" />
            <?php wp_nonce_field('rcl_redeem', 'rcl_nonce'); ?>
            <label class="rcl-label" for="rcl-codice-<?php echo esc_attr($post_id); ?>">
                <?php esc_html_e('Hai un codice? Inseriscilo per sbloccare i materiali:', 'raffaello-codici-libro'); ?>
            </label>
            <div class="rcl-form-row">
                <input type="text" id="rcl-codice-<?php echo esc_attr($post_id); ?>" name="codice" class="rcl-input"
                       placeholder="<?php esc_attr_e('Codice del libro', 'raffaello-codici-libro'); ?>"
                       autocomplete="off" required />
                <button type="submit" class="rcl-button"><?php esc_html_e('Sblocca', 'raffaello-codici-libro'); ?></button>
            </div>
            <div class="rcl-message" role="status" aria-live="polite"></div>
        </form>
        <?php
    }

    /** Mostra l'esito del riscatto nel flusso senza JavaScript (query arg rcl_esito). */
    private function render_esito(): void
    {
        if (empty($_GET['rcl_esito'])) {
            return;
        }
        $esito = sanitize_key(wp_unslash($_GET['rcl_esito']));
        $map = [
            'ok'              => ['ok', __('Contenuti sbloccati correttamente.', 'raffaello-codici-libro')],
            'inesistente'     => ['ko', __('Codice non valido.', 'raffaello-codici-libro')],
            'disattivato'     => ['ko', __('Questo codice non è più attivo.', 'raffaello-codici-libro')],
            'scaduto'         => ['ko', __('Questo codice è scaduto.', 'raffaello-codici-libro')],
            'vuoto'           => ['ko', __('Inserisci un codice.', 'raffaello-codici-libro')],
            'senza_contenuti' => ['ko', __('Codice valido ma non collegato ad alcun contenuto.', 'raffaello-codici-libro')],
            'nonce'           => ['ko', __('Sessione scaduta, riprova.', 'raffaello-codici-libro')],
        ];
        if (!isset($map[$esito])) {
            return;
        }
        printf(
            '<div class="rcl-message rcl-message--%s">%s</div>',
            esc_attr($map[$esito][0]),
            esc_html($map[$esito][1])
        );
    }

    /** Gestione del riscatto via AJAX. */
    public function ajax_redeem(): void
    {
        check_ajax_referer('rcl_redeem', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Devi effettuare l\'accesso.', 'raffaello-codici-libro')], 401);
        }

        $codice = isset($_POST['codice']) ? sanitize_text_field(wp_unslash($_POST['codice'])) : '';
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

        $result = Codes::redeem($codice, get_current_user_id(), $post_id ?: null);

        if ($result['success']) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result, 422);
    }

    /** Gestione del riscatto senza JavaScript (redirect con esito). */
    public function post_redeem(): void
    {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $redirect = $post_id ? get_permalink($post_id) : home_url('/');

        if (!isset($_POST['rcl_nonce']) || !wp_verify_nonce(wp_unslash($_POST['rcl_nonce']), 'rcl_redeem')) {
            wp_safe_redirect(add_query_arg('rcl_esito', 'nonce', $redirect));
            exit;
        }

        if (!is_user_logged_in()) {
            auth_redirect();
        }

        $codice = isset($_POST['codice']) ? sanitize_text_field(wp_unslash($_POST['codice'])) : '';
        $result = Codes::redeem($codice, get_current_user_id(), $post_id ?: null);

        $redirect = add_query_arg('rcl_esito', $result['success'] ? 'ok' : $result['code'], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }
}
