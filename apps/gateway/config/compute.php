<?php

declare(strict_types=1);

return [
    'model_proxy' => [
        'enabled' => (bool) env('ORBIT_SANDBOX_MODEL_PROXY_ENABLED', false),
    ],
];
