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

];
