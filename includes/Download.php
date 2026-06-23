<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Download protetto dei materiali.
 *
 * I file non sono raggiungibili tramite URL diretto: il download passa da un
 * endpoint che verifica autenticazione, nonce e diritto di accesso al contenuto,
 * quindi trasmette il file in streaming.
 */
class Download
{
    const QUERY_VAR = 'rcl_download';

    public function register(): void
    {
        add_action('init', [$this, 'maybe_handle']);
    }

    /**
     * Costruisce l'URL di download protetto per un materiale di un contenuto.
     */
    public static function url(int $post_id, int $attachment_id): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    self::QUERY_VAR => 1,
                    'p'             => $post_id,
                    'a'             => $attachment_id,
                ],
                home_url('/')
            ),
            'rcl_download_' . $post_id . '_' . $attachment_id
        );
    }

    public function maybe_handle(): void
    {
        if (!isset($_GET[self::QUERY_VAR])) {
            return;
        }

        $post_id = isset($_GET['p']) ? (int) $_GET['p'] : 0;
        $attachment_id = isset($_GET['a']) ? (int) $_GET['a'] : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'rcl_download_' . $post_id . '_' . $attachment_id)) {
            wp_die(esc_html__('Link di download non valido o scaduto.', 'raffaello-codici-libro'), 403);
        }

        if (!is_user_logged_in()) {
            auth_redirect();
        }

        $user_id = get_current_user_id();
        if (!Codes::user_can_access($user_id, $post_id)) {
            wp_die(esc_html__('Non hai i permessi per scaricare questo materiale.', 'raffaello-codici-libro'), 403);
        }

        // Il materiale deve appartenere realmente al contenuto richiesto.
        if (!self::material_belongs_to_post($post_id, $attachment_id)) {
            wp_die(esc_html__('Materiale non trovato.', 'raffaello-codici-libro'), 404);
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !is_readable($file)) {
            wp_die(esc_html__('File non disponibile.', 'raffaello-codici-libro'), 404);
        }

        self::stream($file);
    }

    /** Verifica che l'allegato sia tra i materiali del contenuto. */
    private static function material_belongs_to_post(int $post_id, int $attachment_id): bool
    {
        foreach (Materials::get_materials($post_id) as $m) {
            if ((int) $m['attachment_id'] === $attachment_id) {
                return true;
            }
        }
        return false;
    }

    /** Trasmette il file al browser forzando il download. */
    private static function stream(string $file): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        // Annulla eventuale buffering per file di grandi dimensioni.
        while (ob_get_level()) {
            ob_end_clean();
        }

        $filename = basename($file);
        $filetype = wp_check_filetype($filename);
        $mime = $filetype['type'] ?: 'application/octet-stream';

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');

        readfile($file);
        exit;
    }
}
