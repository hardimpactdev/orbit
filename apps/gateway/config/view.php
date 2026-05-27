<?php

declare(strict_types=1);

return [
    'paths' => [
        base_path('apps/gateway/resources/views'),
    ],

    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),
];
