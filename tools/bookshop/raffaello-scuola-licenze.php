<?php
/**
 * Raffaello Scuola: licenze RSCU vendute sul Bookshop.
 *
 * Snippet per il Bookshop (WooCommerce, License Manager for WooCommerce 3.0.20,
 * Action Scheduler di WooCommerce). Va in Code Snippets ("Esegui lo snippet
 * ovunque") oppure in wp-content/mu-plugins/. Istruzioni in README.md.
 *
 * - Quando License Manager assegna le licenze di un ordine, fotografa nella meta
 *   d'ordine quelle con prefisso RSCU (id, chiave, riga, SKU, scadenza) e il sub
 *   di Identity del cliente, poi accoda l'invio con Action Scheduler.
 * - L'invio va a lotti di al massimo 50 licenze e 64 KB, firmati (HMAC-SHA256)
 *   a ogni tentativo; 5 tentativi a 1 min, 5 min, 30 min, 2 h e 6 h, ripetuti
 *   solo su errore di rete, 429 e 5xx.
 * - Rimborso totale o annullamento: accoda la revoca di tutte le licenze RSCU,
 *   anche di quelle assegnate dopo.
 *
 * Costanti in wp-config.php: RAFFAELLO_SCUOLA_URL e RAFFAELLO_SCUOLA_SECRET.
 * Compatibile con PHP 7.4.
 */

defined('ABSPATH') || exit;

const RSCU_PREFISSO = 'RSCU';
const RSCU_META_LICENZE = '_rscu_licenze';
const RSCU_META_CLIENTE = '_rscu_cliente';
const RSCU_META_TENTATIVI = '_rscu_tentativi';
const RSCU_META_REVOCATO = '_rscu_revocato';
const RSCU_AZIONE = 'rscu_invia_licenze';
const RSCU_GRUPPO = 'raffaello-scuola';
const RSCU_LOG = 'raffaello-scuola-licenze';

// Attesa prima di ciascuno dei 5 tentativi: 1 min, 5 min, 30 min, 2 h, 6 h.
const RSCU_ATTESE = [60, 300, 1800, 7200, 21600];

// Limiti dell'endpoint di Raffaello Scuola per un invio.
const RSCU_LOTTO = 50;
const RSCU_MAX_BYTE = 65536;

add_action('lmfwc_event_post_order_license_keys', 'rscu_licenze_assegnate', 20);
add_action(RSCU_AZIONE, 'rscu_invia', 10, 2);
add_filter('woocommerce_order_actions', 'rscu_azioni_ordine', 10, 2);
add_action('woocommerce_order_action_rscu_reinvia', 'rscu_reinvia');
add_action('woocommerce_order_status_refunded', 'rscu_ordine_revocato', 10, 2);
add_action('woocommerce_order_status_cancelled', 'rscu_ordine_revocato', 10, 2);
add_action('woocommerce_order_partially_refunded', 'rscu_rimborso_parziale', 10, 2);

/**
 * License Manager lancia l'evento una volta per ogni riga d'ordine con licenza,
 * ogni volta con tutte le licenze assegnate fin lì: si fotografano solo le RSCU
 * non ancora salvate.
 */
function rscu_licenze_assegnate($args)
{
    $order = wc_get_order($args['orderId'] ?? 0);
    if (!$order) {
        return;
    }

    $salvate = $order->get_meta(RSCU_META_LICENZE);
    $salvate = is_array($salvate) ? $salvate : [];
    $nuove = 0;
    foreach ((array) ($args['licenses'] ?? []) as $license) {
        $chiave = (string) $license->getDecryptedLicenseKey();
        $id = (int) $license->getId();
        if (strpos($chiave, RSCU_PREFISSO) !== 0 || isset($salvate[$id])) {
            continue;
        }
        $salvate[$id] = rscu_fotografa($order, $license, $chiave);
        $nuove++;
    }
    if (!$nuove) {
        return;
    }

    // Il destinatario si fotografa una volta sola, al primo invio dell'ordine.
    if (!$order->get_meta(RSCU_META_CLIENTE)) {
        $sub = $order->get_customer_id() ? (string) get_user_meta($order->get_customer_id(), 'ri_oidc_sub', true) : '';
        $order->update_meta_data(RSCU_META_CLIENTE, ['sub' => $sub !== '' ? $sub : null, 'email' => $order->get_billing_email()]);
    }
    ksort($salvate);
    $order->update_meta_data(RSCU_META_LICENZE, $salvate);
    $order->save();

    rscu_accoda($order, 'licenze_assegnate', false);
    // Ordine revocato prima che License Manager assegnasse queste licenze: si revocano anche loro.
    if ($order->get_meta(RSCU_META_REVOCATO)) {
        rscu_accoda($order, 'licenze_revocate', false);
    }
}

/** Dati di una licenza come li riceve Raffaello Scuola, calcolati una volta sola. */
function rscu_fotografa(WC_Order $order, $license, string $chiave): array
{
    $product_id = (int) $license->getProductId();
    $riga = 0;
    foreach ($order->get_items() as $item_id => $item) {
        if ((int) ($item->get_variation_id() ?: $item->get_product_id()) === $product_id) {
            $riga = (int) $item_id;
            break;
        }
    }
    $product = wc_get_product($product_id);

    // Scadenza: quella di License Manager (GMT); altrimenti pagamento + giorni di validità; altrimenti illimitata.
    $scade_il = null;
    if ($license->getExpiresAt()) {
        $scade_il = gmdate('Y-m-d\TH:i:s\Z', strtotime($license->getExpiresAt() . ' UTC'));
    } elseif ((int) $license->getValidFor() > 0) {
        $pagato = $order->get_date_paid() ?: $order->get_date_created();
        $scade_il = gmdate('Y-m-d\TH:i:s\Z', ($pagato ? $pagato->getTimestamp() : time()) + (int) $license->getValidFor() * DAY_IN_SECONDS);
    }

    return [
        'id'          => (int) $license->getId(),
        'chiave'      => $chiave,
        'riga_ordine' => $riga,
        'prodotto'    => [
            'id'   => $product_id,
            'sku'  => $product ? (string) $product->get_sku() : '',
            'nome' => $product ? $product->get_name() : '',
        ],
        'scade_il'    => $scade_il,
    ];
}

/**
 * Accoda l'invio di un evento per l'ordine, con i tentativi azzerati. Un invio
 * in attesa basta (manda tutte le licenze fotografate); uno in esecuzione no,
 * e se ne accoda un altro. Il reinvio manuale sostituisce quello in attesa.
 */
function rscu_accoda(WC_Order $order, string $evento, bool $sostituisci): void
{
    $args = [$order->get_id(), $evento];
    if ($sostituisci) {
        as_unschedule_all_actions(RSCU_AZIONE, $args, RSCU_GRUPPO);
    } elseif (is_int(as_next_scheduled_action(RSCU_AZIONE, $args, RSCU_GRUPPO))) {
        return;
    }
    $tentativi = $order->get_meta(RSCU_META_TENTATIVI);
    $tentativi = is_array($tentativi) ? $tentativi : [];
    $tentativi[$evento] = 0;
    $order->update_meta_data(RSCU_META_TENTATIVI, $tentativi);
    $order->save();
    rscu_programma($order, $evento, RSCU_ATTESE[0]);
}

/** Programma un tentativo; se Action Scheduler non lo accetta lo dicono la nota d'ordine e il log. */
function rscu_programma(WC_Order $order, string $evento, int $attesa): void
{
    if (!as_schedule_single_action(time() + $attesa, RSCU_AZIONE, [$order->get_id(), $evento], RSCU_GRUPPO)) {
        $order->add_order_note(sprintf('Raffaello Scuola: Action Scheduler non ha programmato l\'invio (%s). Usa l\'azione "Reinvia licenze a Raffaello Scuola".', $evento));
        rscu_log('error', sprintf('Ordine %d: Action Scheduler non ha programmato l\'invio (%s).', $order->get_id(), $evento));
    }
}

/**
 * Job di Action Scheduler: un tentativo di invio dei dati fotografati, a lotti
 * uno dopo l'altro. Riesce solo se riescono tutti; al tentativo successivo si
 * rimanda tutto, tanto l'endpoint è idempotente.
 */
function rscu_invia($order_id, $evento)
{
    $order = wc_get_order($order_id);
    $licenze = $order ? $order->get_meta(RSCU_META_LICENZE) : null;
    if (!is_array($licenze) || !$licenze) {
        return;
    }
    $tentativi = $order->get_meta(RSCU_META_TENTATIVI);
    $tentativi = is_array($tentativi) ? $tentativi : [];
    $tentativo = (int) ($tentativi[$evento] ?? 0) + 1;
    $tentativi[$evento] = $tentativo;
    $order->update_meta_data(RSCU_META_TENTATIVI, $tentativi);
    $order->save();

    if (!defined('RAFFAELLO_SCUOLA_URL') || !defined('RAFFAELLO_SCUOLA_SECRET') || (string) RAFFAELLO_SCUOLA_SECRET === '') {
        $order->add_order_note('Raffaello Scuola: invio non configurato (RAFFAELLO_SCUOLA_URL e RAFFAELLO_SCUOLA_SECRET in wp-config.php). Dopo la configurazione usa "Reinvia licenze a Raffaello Scuola".');
        return;
    }

    $base = [
        'evento'  => $evento,
        'ordine'  => ['id' => $order->get_id(), 'numero' => (string) $order->get_order_number(), 'stato' => $order->get_status()],
        'cliente' => $order->get_meta(RSCU_META_CLIENTE),
    ];
    $azione = $evento === 'licenze_revocate' ? 'revoca' : 'assegnazione';
    $lotti = rscu_lotti($base, array_values($licenze));
    $esiti = [];

    foreach ($lotti as $n => $lotto) {
        $body = wp_json_encode($base + ['licenze' => $lotto]);
        $timestamp = (string) time();
        $response = wp_remote_post(rtrim(RAFFAELLO_SCUOLA_URL, '/') . '/wp-json/raffaello-licenze/v1/eventi', [
            'timeout'    => 15,
            'user-agent' => 'RaffaelloBookshop-Licenze/1.0 (+' . home_url('/') . ')',
            'headers'    => [
                'Content-Type'          => 'application/json',
                'X-Raffaello-Timestamp' => $timestamp,
                'X-Raffaello-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, (string) RAFFAELLO_SCUOLA_SECRET),
            ],
            'body'       => $body,
        ]);
        $status = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $parziali = $status === 200 ? rscu_esiti(json_decode(wp_remote_retrieve_body($response), true), array_column($lotto, 'id')) : null;
        if ($parziali !== null) {
            $esiti += $parziali;
            continue;
        }

        // Una pagina HTML con 200, un 4xx o un JSON senza tutti gli esiti non sono un successo.
        $motivo = (is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . $status . ($status === 200 ? ' senza esiti validi' : ''))
            . (count($lotti) > 1 ? sprintf(', invio %d di %d', $n + 1, count($lotti)) : '');
        rscu_log('warning', sprintf('Ordine %d, %s: tentativo %d non riuscito (%s).', $order->get_id(), $azione, $tentativo, $motivo));
        if ((is_wp_error($response) || $status === 429 || $status >= 500) && $tentativo < count(RSCU_ATTESE)) {
            rscu_programma($order, $evento, RSCU_ATTESE[$tentativo]);
            return;
        }
        $order->add_order_note(sprintf('Raffaello Scuola, %s fallita definitivamente al tentativo %d (%s). Usa l\'azione "Reinvia licenze a Raffaello Scuola".', $azione, $tentativo, $motivo));
        return;
    }

    // Riassunto: l'esito di ogni licenza fino a 10, altrimenti i conteggi.
    if (count($licenze) <= 10) {
        $righe = [];
        foreach ($licenze as $id => $l) {
            $righe[] = rscu_maschera($l['chiave']) . ' ' . $esiti[$id];
        }
    } else {
        $righe = [];
        foreach (array_count_values($esiti) as $esito => $quante) {
            $righe[] = $quante . ' ' . $esito;
        }
    }
    $nota = sprintf('Raffaello Scuola, %s riuscita (%d licenze in %d %s): %s.', $azione, count($licenze), count($lotti), count($lotti) === 1 ? 'invio' : 'invii', implode('; ', $righe));
    $order->add_order_note($nota);
    rscu_log('info', sprintf('Ordine %d, tentativo %d. %s', $order->get_id(), $tentativo, $nota));
}

/** Lotti entro i limiti dell'endpoint: al massimo 50 licenze e 64 KB di corpo. */
function rscu_lotti(array $base, array $licenze): array
{
    $lotti = [];
    $lotto = [];
    foreach ($licenze as $licenza) {
        $prova = array_merge($lotto, [$licenza]);
        if ($lotto && (count($prova) > RSCU_LOTTO || strlen(wp_json_encode($base + ['licenze' => $prova])) > RSCU_MAX_BYTE)) {
            $lotti[] = $lotto;
            $prova = [$licenza];
        }
        $lotto = $prova;
    }
    $lotti[] = $lotto;
    return $lotti;
}

/** Esiti per id licenza se la risposta è ok: true con un esito per ogni licenza inviata, altrimenti null. */
function rscu_esiti($json, array $ids)
{
    if (!is_array($json) || ($json['ok'] ?? null) !== true || !is_array($json['esiti'] ?? null)) {
        return null;
    }
    $esiti = [];
    foreach ($json['esiti'] as $e) {
        if (is_array($e) && isset($e['id'], $e['esito']) && is_string($e['esito'])) {
            $esiti[(int) $e['id']] = $e['esito'];
        }
    }
    foreach ($ids as $id) {
        if (!isset($esiti[$id])) {
            return null;
        }
    }
    return $esiti;
}

/** Azione d'ordine nel menu "Azioni ordine" degli ordini con licenze RSCU. */
function rscu_azioni_ordine($actions, $order = null)
{
    if ($order instanceof WC_Order && $order->get_meta(RSCU_META_LICENZE)) {
        $actions['rscu_reinvia'] = 'Reinvia licenze a Raffaello Scuola';
    }
    return $actions;
}

/** Rimette in coda gli stessi dati fotografati, e la revoca se l'ordine è stato revocato. */
function rscu_reinvia($order)
{
    rscu_accoda($order, 'licenze_assegnate', true);
    if ($order->get_meta(RSCU_META_REVOCATO)) {
        rscu_accoda($order, 'licenze_revocate', true);
    }
    $order->add_order_note('Raffaello Scuola: licenze rimesse in coda per l\'invio.');
}

/**
 * Rimborso totale o annullamento: le licenze già fotografate si revocano ora; la
 * revoca si registra anche prima che License Manager le assegni, se l'ordine ha
 * prodotti che vendono licenze.
 */
function rscu_ordine_revocato($order_id, $order = null)
{
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    // Con la fotografia basta quella: il prodotto può essere stato eliminato o cambiato dopo la vendita.
    if (!$order || (!$order->get_meta(RSCU_META_LICENZE) && !rscu_ha_licenze($order))) {
        return;
    }
    $order->update_meta_data(RSCU_META_REVOCATO, time());
    $order->save();
    if ($order->get_meta(RSCU_META_LICENZE)) {
        rscu_accoda($order, 'licenze_revocate', false);
    }
}

/** Almeno un prodotto (o variazione) che vende licenze, con lo stesso controllo di License Manager. */
function rscu_ha_licenze(WC_Order $order): bool
{
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->get_meta('lmfwc_licensed_product', true)) {
            return true;
        }
    }
    return false;
}

/** Rimborso parziale: non si sa quali licenze togliere, si lascia la decisione all'assistenza. */
function rscu_rimborso_parziale($order_id, $refund_id)
{
    $order = wc_get_order($order_id);
    if ($order && $order->get_meta(RSCU_META_LICENZE)) {
        $order->add_order_note('Rimborso parziale: revocare a mano su Raffaello Scuola le licenze interessate (Licenze > Attivazioni, ricerca per ordine).');
    }
}

/** Chiave per note e log: RSCU-****-AB12. */
function rscu_maschera(string $chiave): string
{
    return substr($chiave, 0, 4) . '-****-' . substr($chiave, -4);
}

function rscu_log(string $level, string $message): void
{
    wc_get_logger()->log($level, $message, ['source' => RSCU_LOG]);
}
