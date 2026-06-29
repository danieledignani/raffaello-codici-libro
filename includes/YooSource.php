<?php

namespace RaffaelloCodiciLibro;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integrazione YOOtheme Pro — Dynamic Content Source.
 *
 * Aggiunge alla source "Site" un campo booleano che indica se l'utente corrente
 * ha accesso ai materiali della pagina in rendering (cioè ha riscattato un codice
 * valido collegato a quella pagina, oppure è amministratore).
 *
 * Il campo è pensato per le Access Condition / Dynamic Condition del builder:
 * si imposta una condizione "non vuoto" su una sezione per mostrarla solo agli
 * utenti sbloccati, e la condizione inversa (Reversed) su una sezione contenente
 * il form [raffaello_codice] mostrata a chi è ancora bloccato.
 *
 * Il valore è calcolato lato server al momento del render: quando l'utente non
 * ha accesso la sezione protetta non viene nemmeno emessa nell'HTML.
 */
class YooSource
{
    /**
     * Handler dell'evento "source.init": estende il tipo "Site" con il campo.
     *
     * objectType() effettua un merge ricorsivo sui tipi esistenti, quindi il
     * campo viene aggiunto alla source "Site" del core senza ridefinirla. Lo
     * mettiamo su "Site" (non su "User") così compare solo nel contesto del sito
     * / utente corrente e non per ogni autore. Il resolver dev'essere un callable
     * serializzabile (metodo statico), perché lo schema viene messo in cache.
     *
     * @param object $source Istanza YOOtheme\Builder\Source.
     */
    public function init_source($source): void
    {
        $source->objectType('Site', [
            'fields' => [
                'rcl_page_access' => [
                    'type'     => 'Boolean',
                    'metadata' => [
                        'label' => __('Accesso materiali (pagina corrente)', 'raffaello-codici-libro'),
                        'group' => __('Codici Libro', 'raffaello-codici-libro'),
                    ],
                    'extensions' => [
                        'call' => self::class . '::resolve_page_access',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Risolve il campo: true se l'utente loggato può accedere ai materiali della
     * pagina attualmente in rendering. Gli amministratori risultano sempre true
     * (anteprima editoriale), coerentemente con Codes::user_can_access.
     *
     * Firma standard del resolver YOOtheme: ($obj, $args, $context, $info).
     */
    public static function resolve_page_access($obj, $args, $context, $info): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        $post_id = (int) get_the_ID();
        if ($post_id <= 0) {
            return false;
        }
        return Codes::user_can_access(get_current_user_id(), $post_id);
    }
}
