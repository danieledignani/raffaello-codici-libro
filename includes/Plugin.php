<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap del plugin: istanzia e registra i componenti.
 */
class Plugin
{
    protected static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        load_plugin_textdomain('raffaello-codici-libro', false, dirname(RCL_PLUGIN_BASENAME) . '/languages');

        // Allineamento schema dopo un aggiornamento (senza riattivazione manuale).
        $this->maybe_upgrade_db();

        (new Frontend())->register();
        (new Download())->register();
        (new Materials())->register();
        (new CodeField())->register();
        (new PageGate())->register();

        if (is_admin()) {
            (new Admin())->register();
        }

        // Integrazione YOOtheme Pro: registra l'elemento builder se il tema è attivo.
        add_action('after_setup_theme', [$this, 'register_yootheme_builder']);
    }

    /** Carica l'elemento builder YOOtheme, solo se YOOtheme Pro è presente. */
    public function register_yootheme_builder(): void
    {
        if (!class_exists('YOOtheme\\Application', false)) {
            return;
        }
        \YOOtheme\Application::getInstance()->load(RCL_PLUGIN_DIR . 'yootheme-customization/bootstrap.php');
    }

    /** Esegue dbDelta se la versione DB salvata è diversa da quella corrente. */
    private function maybe_upgrade_db(): void
    {
        if (get_option('rcl_db_version') !== RCL_VERSION) {
            Database::create_tables();
            update_option('rcl_db_version', RCL_VERSION);
        }
    }
}
