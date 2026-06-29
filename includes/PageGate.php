<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gating dell'intera pagina.
 *
 * Se sul contenuto è attivo l'interruttore "Blocca l'intera pagina con il
 * codice" (vedi CodeField) e l'utente corrente non ha accesso, al posto del
 * contenuto viene mostrato il form di sblocco. Gli amministratori vedono sempre
 * il contenuto (anteprima editoriale, tramite Codes::user_can_access). Il blocco
 * scatta solo se la pagina ha almeno un codice associato.
 */
class PageGate
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_gate']);
    }

    public function maybe_gate(): void
    {
        if (is_admin() || is_feed() || !is_singular()) {
            return;
        }

        $post_id = (int) get_queried_object_id();
        if (!$post_id) {
            return;
        }

        // Blocco pagina attivo per questo contenuto?
        if (!get_post_meta($post_id, CodeField::META_BLOCK, true)) {
            return;
        }

        // Senza codici associati non c'è nulla da sbloccare: nessun blocco.
        if (empty(Database::get_post_codes($post_id))) {
            return;
        }

        // Accesso consentito (utente abilitato o amministratore): pagina visibile.
        $user_id = get_current_user_id();
        if ($user_id && Codes::user_can_access($user_id, $post_id)) {
            return;
        }

        $this->render_gate($post_id);
    }

    /** Mostra il form di sblocco al posto del contenuto della pagina. */
    private function render_gate(int $post_id): void
    {
        get_header();
        ?>
        <div class="rcl-page-gate">
            <div class="rcl-page-gate__inner">
                <?php $title = get_the_title($post_id); ?>
                <?php if ($title) : ?>
                    <h1 class="rcl-page-gate__title"><?php echo esc_html($title); ?></h1>
                <?php endif; ?>
                <p class="rcl-page-gate__intro">
                    <?php esc_html_e('Questo contenuto è riservato. Inserisci il codice del tuo libro per accedere.', 'raffaello-codici-libro'); ?>
                </p>
                <?php echo do_shortcode('[raffaello_codice post_id="' . $post_id . '"]'); ?>
            </div>
        </div>
        <?php
        get_footer();
        exit;
    }
}
