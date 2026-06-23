<?php
/**
 * Disinstallazione: rimuove tabelle, opzioni e meta dei materiali.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'rcl_riscatti',
    $wpdb->prefix . 'rcl_codice_post',
    $wpdb->prefix . 'rcl_codici',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('rcl_db_version');

$wpdb->query(
    $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_rcl_materiali')
);
