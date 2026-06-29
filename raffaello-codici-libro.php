<?php
/**
 * Plugin Name: Raffaello Codici Libro
 * Plugin URI: https://raffaellolibri.it
 * Description: Sblocco di aree riservate e materiali scaricabili tramite i codici stampati sui libri scolastici. Integrato con Raffaello Identity (SSO). Mostra i materiali bloccati in anteprima con form di sblocco contestuale e registra l'abbinamento codice ↔ utente.
 * Version: 1.2.1
 * Author: Gruppo Raffaello
 * Text Domain: raffaello-codici-libro
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RCL_VERSION', '1.2.1');
define('RCL_PLUGIN_FILE', __FILE__);
define('RCL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RCL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RCL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Aggiornamento automatico da GitHub: legge la versione corrente da un JSON
// pubblicato nel repo e, se più alta di quella installata, scarica lo zip
// dalla release corrispondente.
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://raw.githubusercontent.com/danieledignani/raffaello-codici-libro/master/.github/update-metadata/raffaello-codici-libro.json',
    __FILE__,
    'raffaello-codici-libro'
);

// Autoload classi del plugin (namespace RaffaelloCodiciLibro\ → includes/).
spl_autoload_register(function ($class) {
    $prefix = 'RaffaelloCodiciLibro\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = RCL_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

// Creazione/aggiornamento tabelle in fase di attivazione.
register_activation_hook(__FILE__, function () {
    \RaffaelloCodiciLibro\Database::create_tables();
    add_option('rcl_db_version', RCL_VERSION);
});

// Avvio del plugin.
add_action('plugins_loaded', function () {
    \RaffaelloCodiciLibro\Plugin::instance()->boot();
});
