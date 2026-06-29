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

    // NOTA: l'integrazione "source.init" che estendeva la source "Site" con il
    // campo "Accesso materiali" (gating di sezione) è temporaneamente disattivata:
    // nella 1.3.0 causava un errore fatale durante il render front-end di YOOtheme.
    // La classe YooSource resta nel plugin in attesa di reintegrarla correttamente
    // una volta individuata la causa esatta sul server (vedi readme/changelog).

];
