<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log del plugin: WooCommerce > Stato > Log (sorgente raffaello-licenze) se
 * WooCommerce è attivo, altrimenti error_log. Mai codici completi né segreti:
 * i codici passano da Codes::mask().
 */
class Log
{
    const SOURCE = 'raffaello-licenze';

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** Email per i log: d***@dominio.it. */
    public static function email(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? ($email === '' ? '' : '***') : substr($email, 0, 1) . '***' . substr($email, $at);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if ($context) {
            $message .= ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => self::SOURCE]);
            return;
        }
        error_log('[' . self::SOURCE . '] ' . $level . ': ' . $message);
    }
}
