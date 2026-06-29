<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logica di riscatto e verifica accesso.
 *
 * Il codice è condiviso tra tutte le copie del libro: non si "consuma" al
 * primo utilizzo, ma ogni riscatto genera un record collegato all'utente.
 */
class Codes
{
    /**
     * Riscatta un codice per l'utente indicato.
     *
     * @param string   $codice_raw Codice grezzo inserito dall'utente.
     * @param int      $user_id    Utente autenticato (via Identity/SSO).
     * @param int|null $post_id    Contenuto dal quale è partito il riscatto (per il messaggio contestuale).
     *
     * @return array { success: bool, code: string, message: string, post_ids: int[], unlocks_current: bool }
     */
    public static function redeem(string $codice_raw, int $user_id, ?int $post_id = null): array
    {
        $norm = Database::normalize_code($codice_raw);

        if ($norm === '') {
            return self::result(false, 'vuoto', __('Inserisci un codice.', 'raffaello-codici-libro'));
        }

        $codice = Database::find_code($norm);
        if (!$codice) {
            return self::result(false, 'inesistente', __('Codice non valido.', 'raffaello-codici-libro'));
        }

        if ((int) $codice->attivo !== 1) {
            return self::result(false, 'disattivato', __('Questo codice non è più attivo.', 'raffaello-codici-libro'));
        }

        if (!empty($codice->data_scadenza) && strtotime($codice->data_scadenza) < current_time('timestamp')) {
            return self::result(false, 'scaduto', __('Questo codice è scaduto.', 'raffaello-codici-libro'));
        }

        $post_ids = Database::get_code_posts((int) $codice->id);
        if (empty($post_ids)) {
            return self::result(false, 'senza_contenuti', __('Codice valido ma non collegato ad alcun contenuto. Contatta l\'assistenza.', 'raffaello-codici-libro'));
        }

        Database::record_redemption((int) $codice->id, $user_id, self::client_ip());

        $unlocks_current = $post_id !== null && in_array((int) $post_id, $post_ids, true);

        $message = $unlocks_current
            ? __('Contenuti sbloccati correttamente.', 'raffaello-codici-libro')
            : __('Codice riscattato: trovi i contenuti sbloccati nella tua area riservata.', 'raffaello-codici-libro');

        $result = self::result(true, 'ok', $message, $post_ids);
        $result['unlocks_current'] = $unlocks_current;
        return $result;
    }

    /** Verifica se l'utente ha accesso a un contenuto. */
    public static function user_can_access(int $user_id, int $post_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }
        // Gli amministratori vedono sempre i contenuti (anteprima editoriale).
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        return Database::user_has_access($user_id, $post_id);
    }

    /** Costruisce la struttura di risposta standard. */
    private static function result(bool $success, string $code, string $message, array $post_ids = []): array
    {
        return [
            'success'         => $success,
            'code'            => $code,
            'message'         => $message,
            'post_ids'        => $post_ids,
            'unlocks_current' => false,
        ];
    }

    /**
     * IP del client, validato (solo a scopo di log/anti-abuso).
     *
     * Dietro un reverse proxy / CDN (es. Cloudflare) REMOTE_ADDR è l'IP del proxy,
     * non quello reale del visitatore. Proviamo quindi, in ordine, alcune
     * intestazioni note impostate dai proxy, con fallback a REMOTE_ADDR.
     *
     * NB: queste intestazioni sono attendibili solo se il sito riceve traffico
     * esclusivamente tramite il proxy (come qui, dietro Cloudflare); altrimenti
     * sono falsificabili dal client. L'elenco è filtrabile via
     * 'rcl_client_ip_headers' per adattarlo ad altri proxy o per disabilitare la
     * lettura delle intestazioni (restituendo un array vuoto).
     */
    private static function client_ip(): ?string
    {
        $headers = apply_filters('rcl_client_ip_headers', [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // proxy/CDN standard (lista separata da virgole)
        ]);

        foreach ((array) $headers as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            // X-Forwarded-For può contenere più IP: il primo è il client originale.
            foreach (explode(',', (string) wp_unslash($_SERVER[$header])) as $candidate) {
                $ip = filter_var(trim($candidate), FILTER_VALIDATE_IP);
                if ($ip) {
                    return $ip;
                }
            }
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ?: null;
    }
}
