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
    // usato dalle Access Condition / Dynamic Condition del builder per il
    // gating di sezione. Il listener è registrato come metodo STATICO (senza
    // '@'), nello stesso formato usato da YOOtheme core/WooCommerce.
    'events' => [
        'source.init' => [
            \RaffaelloCodiciLibro\YooSource::class => 'init_source',
        ],
    ],

];
