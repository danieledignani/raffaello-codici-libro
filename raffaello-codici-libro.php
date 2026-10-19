<?php
/**
 * Plugin Name: Raffaello Licenze
 * Plugin URI: https://raffaellolibri.it
 * Description: Licenze di accesso a pagine, materiali scaricabili e corsi LearnDash: codici stampati sui libri, acquisti sul Bookshop attivati in automatico sul profilo di Raffaello Identity, attivazioni manuali per l'assistenza.
 * Version: 1.5.2
 * Author: Gruppo Raffaello
 * Text Domain: raffaello-codici-libro
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RCL_VERSION', '1.5.2');
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

// Creazione/aggiornamento tabelle in fase di attivazione; la versione avanza solo se riesce.
register_activation_hook(__FILE__, function () {
    if (\RaffaelloCodiciLibro\Database::create_tables()) {
        update_option('rcl_db_version', RCL_VERSION);
    }
});

// Il cron giornaliero delle scadenze si riprogramma alla riattivazione.
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(\RaffaelloCodiciLibro\LearnDash::CRON);
});

// Avvio del plugin.
add_action('plugins_loaded', function () {
    \RaffaelloCodiciLibro\Plugin::instance()->boot();
});
