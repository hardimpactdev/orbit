<?php

declare(strict_types=1);

return [
    'orbit_claims_enabled' => (bool) env('ORBIT_SANDBOX_ORBIT_CLAIMS_ENABLED', false),
    'model_proxy' => [
        'enabled' => (bool) env('ORBIT_SANDBOX_MODEL_PROXY_ENABLED', false),
    ],
    'pi' => [
        'models' => json_decode((string) env('ORBIT_SANDBOX_PI_MODELS', '[]'), true),
    ],
    'incus' => [
        'enabled' => (bool) env('ORBIT_INCUS_ENABLED', false),
        'hosts' => json_decode((string) env('ORBIT_INCUS_HOSTS', '[]'), true),
    ],
];
