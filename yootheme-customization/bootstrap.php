<?php

use YOOtheme\Builder;
use YOOtheme\Path;

return [

    // Registra gli elementi builder del plugin.
    'extend' => [

        Builder::class => function (Builder $builder) {
            $builder->addTypePath(Path::get('./elements/*/element.json'));
        },

    ],

    // Estende la source "Site" con il campo booleano "Accesso materiali",
    // usato dalle Access Condition / Dynamic Condition del builder per
    // mostrare/nascondere intere sezioni in base ai codici riscattati
    // dall'utente per la pagina corrente (gating di sezione).
    'events' => [
        'source.init' => [
            \RaffaelloCodiciLibro\YooSource::class => ['init_source'],
        ],
    ],

];
