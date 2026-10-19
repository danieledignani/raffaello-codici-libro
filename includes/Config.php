<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Segreto condiviso con il Bookshop. La costante RCL_BOOKSHOP_SECRET di wp-config
 * vince sempre, anche vuota (e allora non vale niente). Senza costante vale
 * l'opzione salvata dalle impostazioni, ma solo sul sito che l'ha salvata: un
 * database copiato da un altro ambiente non si porta dietro il collegamento.
 */
class Config
{
    const CONSTANT = 'RCL_BOOKSHOP_SECRET';
    const OPTION = 'rcl_bookshop';
    const MIN_LENGTH = 32;

    /**
     * Stato del segreto: fonte ('wp-config', 'impostazioni' o '' se non c'è), segreto
     * ('' se non vale) e problema (null se vale, altrimenti 'mancante', 'altro_host',
     * 'vuoto' o 'corto'). Con 'altro_host', l'host che l'ha salvato.
     */
    public static function state(): array
    {
        if (defined(self::CONSTANT)) {
            return self::check('wp-config', (string) constant(self::CONSTANT));
        }
        $saved = get_option(self::OPTION);
        if (!$saved) {
            return ['fonte' => '', 'segreto' => '', 'problema' => 'mancante'];
        }
        if ($saved['host'] !== self::host()) {
            return ['fonte' => 'impostazioni', 'segreto' => '', 'problema' => 'altro_host', 'host' => $saved['host']];
        }
        return self::check('impostazioni', $saved['secret']);
    }

    /** Il segreto valido, o '' se non è configurato. */
    public static function secret(): string
    {
        return self::state()['segreto'];
    }

    /**
     * Salva il segreto per questo sito; non si carica a ogni richiesta. Vero se dopo la
     * scrittura è il segreto che vale: con la costante definita vale la costante.
     */
    public static function save(string $secret): bool
    {
        global $wpdb;
        // Una scrittura fallita non deve copiare il segreto nel log: wpdb::print_error() scrive la query intera.
        $prev = $wpdb->suppress_errors(true);
        update_option(self::OPTION, ['secret' => $secret, 'host' => self::host()], false);
        $wpdb->suppress_errors($prev);
        return self::secret() === $secret;
    }

    /**
     * Vero se l'opzione è stata cancellata. Non si rilegge: delete_option() la segna come
     * assente nella cache anche quando il DELETE fallisce.
     */
    public static function delete(): bool
    {
        return delete_option(self::OPTION);
    }

    /** Host di home_url(), a cui è legato il segreto salvato; gli host non distinguono le maiuscole. */
    public static function host(): string
    {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    private static function check(string $fonte, string $secret): array
    {
        $problema = $secret === '' ? 'vuoto' : (strlen($secret) < self::MIN_LENGTH ? 'corto' : null);
        return ['fonte' => $fonte, 'segreto' => $problema === null ? $secret : '', 'problema' => $problema];
    }
}
