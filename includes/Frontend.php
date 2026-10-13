<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend: shortcode dei materiali, form di sblocco contestuale, pagina "I miei
 * contenuti", riscatto (AJAX con fallback senza JavaScript) ed enqueue degli asset.
 */
class Frontend
{
    /** Pagina con [raffaello_miei_contenuti], scelta nelle impostazioni. */
    const OPTION_MIEI_CONTENUTI = 'rcl_pagina_miei_contenuti';

    /** Query arg e transient dell'esito nel flusso senza JavaScript. */
    const ESITO = 'rcl_esito';

    /** L'esito è già nella pagina: si mostra una volta sola per richiesta. */
    private $esito_mostrato = false;

    public function register(): void
    {
        add_shortcode('raffaello_materiali', [$this, 'shortcode_materiali']);
        add_shortcode('raffaello_codice', [$this, 'shortcode_form']);
        add_shortcode('raffaello_miei_contenuti', [$this, 'shortcode_miei_contenuti']);
        // Dopo gli shortcode (priorità 11), che mostrano l'esito vicino al form; senza loop, in fondo.
        add_filter('the_content', [$this, 'esito_in_testa'], 12);
        add_action('wp_footer', [$this, 'esito_flottante']);

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
            $this->render_esito();
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

    /**
     * Shortcode [raffaello_miei_contenuti]: licenze attive e non più attive
     * dell'utente, con la scadenza e i link ai contenuti, e il form del codice.
     */
    public function shortcode_miei_contenuti($atts): string
    {
        ob_start();
        echo '<div class="rcl-miei-contenuti">';
        if (is_user_logged_in()) {
            $this->render_licenses(get_current_user_id());
        }
        echo '<div class="rcl-materiali">';
        $this->render_form((int) get_the_ID());
        echo '</div></div>';
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

    /** Licenze dell'utente: attive con scadenza e contenuti, poi scadute o sospese. */
    private function render_licenses(int $user_id): void
    {
        $now = Database::now();
        $licenze = [];
        foreach (Activations::of_user($user_id) as $a) {
            $l = $licenze[$a->licenza_id] ?? ['nome' => $a->nome, 'attiva' => (bool) (int) $a->attiva, 'illimitata' => false, 'scade_il' => ''];
            if ($a->scade_il === null) {
                $l['illimitata'] = true;
            } elseif ($a->scade_il > $l['scade_il']) {
                $l['scade_il'] = $a->scade_il;
            }
            $licenze[$a->licenza_id] = $l;
        }

        $attive = $altre = '';
        foreach ($licenze as $licenza_id => $l) {
            $valida = $l['attiva'] && ($l['illimitata'] || $l['scade_il'] > $now);
            $item = '<li class="rcl-licenza"><strong>' . esc_html($l['nome']) . '</strong> <span class="rcl-licenza__stato">';
            if ($valida) {
                /* translators: %s: data */
                $item .= esc_html($l['illimitata'] ? __('senza scadenza', 'raffaello-codici-libro') : sprintf(__('fino al %s', 'raffaello-codici-libro'), Codes::date($l['scade_il'])));
                $item .= '</span>' . self::links_html((int) $licenza_id) . '</li>';
                $attive .= $item;
            } else {
                /* translators: %s: data */
                $item .= esc_html($l['attiva'] ? sprintf(__('scaduta il %s', 'raffaello-codici-libro'), Codes::date($l['scade_il'])) : __('sospesa', 'raffaello-codici-libro'));
                $altre .= $item . '</span></li>';
            }
        }

        if ($attive === '' && $altre === '') {
            echo '<p class="rcl-miei-contenuti__vuoto">' . esc_html__('Non hai ancora licenze. Inserisci qui sotto il codice del tuo libro o quello ricevuto con l\'acquisto.', 'raffaello-codici-libro') . '</p>';
            return;
        }
        if ($attive !== '') {
            echo '<h3>' . esc_html__('Licenze attive', 'raffaello-codici-libro') . '</h3><ul class="rcl-licenze">' . $attive . '</ul>';
        }
        if ($altre !== '') {
            echo '<h3>' . esc_html__('Licenze non più attive', 'raffaello-codici-libro') . '</h3><ul class="rcl-licenze rcl-licenze--scadute">' . $altre . '</ul>';
        }
    }

    /** Contenuti pubblicati di una licenza, come elenco di link. */
    private static function links_html(int $licenza_id): string
    {
        $html = '';
        foreach (Licenses::posts($licenza_id) as $id) {
            if (get_post_status($id) === 'publish') {
                $html .= '<li><a href="' . esc_url(get_permalink($id)) . '">' . esc_html(get_the_title($id)) . '</a></li>';
            }
        }
        return $html !== '' ? '<ul class="rcl-link-contenuti">' . $html . '</ul>' : '';
    }

    /** Form di inserimento codice. */
    private function render_form(int $post_id): void
    {
        if (!is_user_logged_in()) {
            // Se Raffaello Identity (SSO) è attivo usa il suo login OIDC, come fa
            // il link "Accedi" del menu; altrimenti fallback al login WordPress.
            $login_url = function_exists('ri_login_url')
                ? ri_login_url()
                : wp_login_url(get_permalink($post_id) ?: home_url('/'));
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
                       placeholder="<?php esc_attr_e('Codice del libro o dell\'acquisto', 'raffaello-codici-libro'); ?>"
                       autocomplete="off" required />
                <button type="submit" class="rcl-button"><?php esc_html_e('Sblocca', 'raffaello-codici-libro'); ?></button>
            </div>
            <div class="rcl-message" role="status" aria-live="polite"></div>
        </form>
        <?php
    }

    /**
     * Esito del flusso senza JavaScript: lo stesso risultato dell'AJAX, salvato
     * per pochi minuti e mostrato una volta sola per richiesta.
     */
    private function render_esito(string $classe = ''): void
    {
        if ($this->esito_mostrato || empty($_GET[self::ESITO])) {
            return;
        }
        $token = sanitize_key(wp_unslash($_GET[self::ESITO]));
        if ($token === 'nonce') {
            $this->esito_mostrato = true;
            echo self::result_html(['success' => false, 'message' => __('Sessione scaduta, riprova.', 'raffaello-codici-libro'), 'links' => []], $classe);
            return;
        }
        $key = self::ESITO . '_' . get_current_user_id() . '_' . $token;
        $result = get_transient($key);
        if (is_array($result)) {
            $this->esito_mostrato = true;
            delete_transient($key);
            echo self::result_html($result, $classe);
        }
    }

    /**
     * Esito su una pagina che non mostra più il form, per esempio quella appena
     * sbloccata: in testa al contenuto della pagina richiesta.
     */
    public function esito_in_testa($content)
    {
        // Solo il contenuto della pagina richiesta: non un estratto, non un post annidato (Query Loop).
        if (!is_singular() || !in_the_loop() || !is_main_query() || doing_filter('get_the_excerpt')
            || (int) get_the_ID() !== (int) get_queried_object_id()) {
            return $content;
        }
        ob_start();
        $this->render_esito();
        return ob_get_clean() . $content;
    }

    /**
     * Ripiego per le pagine senza loop (builder YOOtheme): l'esito non ancora
     * mostrato in un riquadro fisso.
     */
    public function esito_flottante(): void
    {
        $this->render_esito('rcl-message--flottante');
    }

    /** HTML dell'esito, uguale per AJAX e invio senza JavaScript. */
    public static function result_html(array $result, string $classe = ''): string
    {
        $html = '<p>' . esc_html($result['message']) . '</p>';
        if ($result['success'] && $result['links']) {
            $html .= '<p>' . esc_html__('Ora puoi accedere a:', 'raffaello-codici-libro') . '</p><ul class="rcl-link-contenuti">';
            foreach ($result['links'] as $link) {
                $html .= '<li><a href="' . esc_url($link['url']) . '">' . esc_html($link['title']) . '</a></li>';
            }
            $html .= '</ul>';
        }
        $page = (int) get_option(self::OPTION_MIEI_CONTENUTI);
        if ($result['success'] && $page && get_post_status($page) === 'publish') {
            $html .= '<p><a href="' . esc_url(get_permalink($page)) . '">' . esc_html__('Vedi tutti i tuoi contenuti', 'raffaello-codici-libro') . '</a></p>';
        }
        return '<div class="rcl-message rcl-message--' . ($result['success'] ? 'ok' : 'ko') . ($classe !== '' ? ' ' . $classe : '') . '" role="status" aria-live="polite">' . $html . '</div>';
    }

    /** Riscatto comune ad AJAX e POST: un errore del database diventa un messaggio. */
    private function redeem(): array
    {
        $codice = isset($_POST['codice']) ? sanitize_text_field(wp_unslash($_POST['codice'])) : '';
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        try {
            return Codes::redeem($codice, get_current_user_id(), $post_id ?: null);
        } catch (\Throwable $e) {
            Log::error('Riscatto non riuscito (' . get_class($e) . ').', ['user_id' => get_current_user_id()]);
            return ['success' => false, 'code' => 'errore', 'message' => __('Si è verificato un errore. Riprova.', 'raffaello-codici-libro'), 'post_ids' => [], 'links' => [], 'unlocks_current' => false];
        }
    }

    /** Gestione del riscatto via AJAX. */
    public function ajax_redeem(): void
    {
        check_ajax_referer('rcl_redeem', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Devi effettuare l\'accesso.', 'raffaello-codici-libro')], 401);
        }

        $result = $this->redeem();
        $result['html'] = self::result_html($result);

        if ($result['success']) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result, 422);
    }

    /** Gestione del riscatto senza JavaScript (redirect con l'esito). */
    public function post_redeem(): void
    {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $redirect = $post_id ? get_permalink($post_id) : home_url('/');

        if (!isset($_POST['rcl_nonce']) || !wp_verify_nonce(wp_unslash($_POST['rcl_nonce']), 'rcl_redeem')) {
            wp_safe_redirect(add_query_arg(self::ESITO, 'nonce', $redirect));
            exit;
        }

        if (!is_user_logged_in()) {
            auth_redirect();
        }

        $token = strtolower(wp_generate_password(12, false));
        set_transient(self::ESITO . '_' . get_current_user_id() . '_' . $token, $this->redeem(), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(self::ESITO, $token, $redirect));
        exit;
    }
}
