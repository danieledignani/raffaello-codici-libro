<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integrazione YOOtheme Pro — Dynamic Content Source.
 *
 * Estende il tipo "Site" della source di YOOtheme con un campo booleano
 * "Accesso materiali (pagina corrente)" (gruppo "Codici Libro", marcato come
 * condizione): indica se l'utente corrente può accedere ai materiali della
 * pagina in rendering (ha riscattato un codice valido collegato, oppure è
 * amministratore, coerentemente con Codes::user_can_access).
 *
 * Pensato per le Access Condition / Dynamic Condition del builder: condizione
 * "non vuoto" sulla sezione protetta e condizione inversa (Reversed) sulla
 * sezione con il form [raffaello_codice]. Valutato lato server al render: la
 * sezione protetta non viene emessa nell'HTML quando l'utente non ha accesso.
 *
 * Formati allineati al codice di YOOtheme: core builder-source SiteType (campo
 * "is_guest" con 'condition' => true; campi "page_url"/"request" con
 * 'extensions.call') e integrazione WooCommerce (campi Boolean sul tipo Site).
 * Il listener è registrato come metodo STATICO in bootstrap.php (senza '@'),
 * come fa LoadSourceTypes di WooCommerce/core; il resolver è un callable
 * serializzabile (stringa "Classe::metodo"), perché lo schema viene messo in
 * cache (niente closure nei resolver).
 */
class YooSource
{
    /**
     * Listener dell'evento "source.init": estende il tipo "Site".
     *
     * @param \YOOtheme\Builder\Source $source
     */
    public static function init_source($source): void
    {
        $source->objectType('Site', fn() => self::config());
    }

    /**
     * Definizione del campo aggiunto (in merge) al tipo "Site".
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'fields' => [
                'rcl_page_access' => [
                    'type'     => 'Boolean',
                    'metadata' => [
                        'label'     => 'Accesso materiali (pagina corrente)',
                        'group'     => 'Codici Libro',
                        'condition' => true,
                    ],
                    'extensions' => [
                        'call' => __CLASS__ . '::resolve_page_access',
                    ],
                ],
            ],
        ];
    }

    /**
     * Risolve il campo: true se l'utente loggato può accedere ai materiali della
     * pagina attualmente in rendering. Nessun parametro richiesto (usa i globali
     * di WordPress), come i resolver senza argomenti del core (es. resolveRequest).
     */
    public static function resolve_page_access(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        $post_id = (int) get_the_ID();

        return $post_id > 0 && Codes::user_can_access(get_current_user_id(), $post_id);
    }
}
