<?php
/**
 * Seed di dati di prova per Raffaello Codici Libro — SOLO per ambienti di staging/test.
 *
 * Crea una pagina di esempio con materiali scaricabili e alcuni codici di prova
 * (validi e scaduto) già associati alla pagina, così da provare l'intero flusso:
 * anteprima bloccata → inserimento codice → sblocco → download protetto.
 *
 * Uso:
 *   wp eval-file tools/seed.php             (consigliato, se WP-CLI è disponibile)
 *   php tools/seed.php                       (cerca wp-load.php risalendo le cartelle)
 *   php tools/seed.php --remove              (rimuove i dati di seed creati)
 *
 * I dati di seed sono marcati (option/meta dedicati) per poter essere rimossi
 * in modo pulito senza toccare i dati reali.
 */

if (php_sapi_name() !== 'cli' && !defined('WP_CLI')) {
    // Evita esecuzione accidentale via web.
    http_response_code(403);
    exit('Questo script va eseguito da riga di comando (CLI).');
}

// Bootstrap di WordPress se non già caricato (es. esecuzione con `php`).
if (!function_exists('add_action')) {
    $dir = __DIR__;
    $found = '';
    for ($i = 0; $i < 8; $i++) {
        $candidate = $dir . '/wp-load.php';
        if (file_exists($candidate)) {
            $found = $candidate;
            break;
        }
        $dir = dirname($dir);
    }
    if (!$found) {
        fwrite(STDERR, "Impossibile trovare wp-load.php risalendo le cartelle.\n");
        exit(1);
    }
    require_once $found;
}

if (!class_exists('RaffaelloCodiciLibro\\Database')) {
    fwrite(STDERR, "Plugin Raffaello Codici Libro non attivo. Attivalo prima di eseguire il seed.\n");
    exit(1);
}

use RaffaelloCodiciLibro\Database;
use RaffaelloCodiciLibro\Materials;

$argv = isset($argv) ? $argv : [];
$remove = in_array('--remove', $argv, true);

const RCL_SEED_PAGE_OPTION = 'rcl_seed_page_id';
const RCL_SEED_CODE_PREFIX = 'DEMO';

/** Scrive un messaggio a console (compatibile WP-CLI e php). */
function rcl_seed_log(string $msg): void
{
    if (defined('WP_CLI') && class_exists('WP_CLI')) {
        \WP_CLI::log($msg);
    } else {
        fwrite(STDOUT, $msg . "\n");
    }
}

/* -------------------------------------------------------------------------
 * Rimozione dei dati di seed
 * ---------------------------------------------------------------------- */
if ($remove) {
    global $wpdb;

    // Elimina i codici di seed (prefisso DEMO) e relativi riscatti/associazioni.
    $codici = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id FROM " . Database::table('codici') . " WHERE codice LIKE %s",
            $wpdb->esc_like(RCL_SEED_CODE_PREFIX) . '%'
        )
    );
    foreach ($codici as $c) {
        Database::delete_code((int) $c->id);
    }
    rcl_seed_log(sprintf('Rimossi %d codici di seed.', count($codici)));

    // Elimina la pagina di esempio e i suoi allegati.
    $page_id = (int) get_option(RCL_SEED_PAGE_OPTION);
    if ($page_id) {
        foreach (Materials::get_materials($page_id) as $m) {
            if (!empty($m['attachment_id'])) {
                wp_delete_attachment((int) $m['attachment_id'], true);
            }
        }
        wp_delete_post($page_id, true);
        delete_option(RCL_SEED_PAGE_OPTION);
        rcl_seed_log('Pagina di esempio e allegati rimossi.');
    }

    rcl_seed_log('Seed rimosso.');
    exit(0);
}

/* -------------------------------------------------------------------------
 * Creazione dei dati di seed
 * ---------------------------------------------------------------------- */

// 1) Pagina di esempio (riusa quella esistente se già creata dal seed).
$page_id = (int) get_option(RCL_SEED_PAGE_OPTION);
if (!$page_id || !get_post($page_id)) {
    $page_id = wp_insert_post([
        'post_title'   => 'Materiali di prova — Codici Libro',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => "Questa è una pagina di test per il plugin Codici Libro.\n\n[raffaello_materiali]",
    ]);
    if (is_wp_error($page_id)) {
        fwrite(STDERR, 'Creazione pagina fallita: ' . $page_id->get_error_message() . "\n");
        exit(1);
    }
    update_option(RCL_SEED_PAGE_OPTION, $page_id);
    rcl_seed_log('Creata pagina di esempio #' . $page_id);
} else {
    rcl_seed_log('Riuso pagina di esempio esistente #' . $page_id);
}

// 2) Allegati di prova (creati nella media library) + meta materiali.
require_once ABSPATH . 'wp-admin/includes/image.php';

$upload = wp_upload_dir();
$materiali = [];
$esempi = [
    ['titolo' => 'Scheda esercizi (PDF di prova)', 'file' => 'rcl-esercizi-prova.txt', 'body' => "Esercizi di prova - Codici Libro\n"],
    ['titolo' => 'Audio lezione (file di prova)',  'file' => 'rcl-audio-prova.txt',     'body' => "Audio lezione di prova - Codici Libro\n"],
];

foreach ($esempi as $i => $e) {
    $path = trailingslashit($upload['path']) . $e['file'];
    file_put_contents($path, $e['body']);

    $filetype = wp_check_filetype(basename($path), null);
    $attach_id = wp_insert_attachment([
        'post_mime_type' => $filetype['type'] ?: 'text/plain',
        'post_title'     => $e['titolo'],
        'post_status'    => 'inherit',
    ], $path, $page_id);

    $meta = wp_generate_attachment_metadata($attach_id, $path);
    wp_update_attachment_metadata($attach_id, $meta);

    $materiali[] = [
        'id'            => 'seed' . ($i + 1) . '_' . $attach_id,
        'titolo'        => $e['titolo'],
        'attachment_id' => $attach_id,
    ];
}
update_post_meta($page_id, Materials::META_KEY, $materiali);
rcl_seed_log('Associati ' . count($materiali) . ' materiali alla pagina.');

// 3) Codici di prova: due validi + uno scaduto, tutti collegati alla pagina.
$oggi = current_time('Y-m-d');
$codici_seed = [
    ['codice' => RCL_SEED_CODE_PREFIX . '-1234', 'descrizione' => 'Codice demo valido 1', 'scadenza' => null],
    ['codice' => RCL_SEED_CODE_PREFIX . '-5678', 'descrizione' => 'Codice demo valido 2', 'scadenza' => null],
    ['codice' => RCL_SEED_CODE_PREFIX . '-SCAD', 'descrizione' => 'Codice demo SCADUTO',  'scadenza' => '2020-01-01 00:00:00'],
];

$creati = [];
foreach ($codici_seed as $cs) {
    $norm = Database::normalize_code($cs['codice']);
    $existing = Database::find_code($norm);
    if ($existing) {
        $id = (int) $existing->id;
    } else {
        $res = Database::insert_code($norm, $cs['descrizione'], true, $cs['scadenza']);
        if (is_wp_error($res)) {
            rcl_seed_log('  ! Salto ' . $cs['codice'] . ': ' . $res->get_error_message());
            continue;
        }
        $id = (int) $res;
    }
    Database::set_code_posts($id, [$page_id]);
    $creati[] = $cs['codice'];
}

rcl_seed_log('');
rcl_seed_log('=== Seed completato ===');
rcl_seed_log('Pagina di test: ' . get_permalink($page_id));
rcl_seed_log('Codici creati/associati: ' . implode(', ', $creati));
rcl_seed_log('  - ' . RCL_SEED_CODE_PREFIX . '-1234 / ' . RCL_SEED_CODE_PREFIX . '-5678  → validi (sbloccano la pagina)');
rcl_seed_log('  - ' . RCL_SEED_CODE_PREFIX . '-SCAD                 → scaduto (deve dare errore "scaduto")');
rcl_seed_log('');
rcl_seed_log('Per rimuovere il seed: php tools/seed.php --remove');
