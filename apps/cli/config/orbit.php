<?php

declare(strict_types=1);

return [
    'gateway' => [
        'url' => env('ORBIT_GATEWAY_URL'),
        'identity' => env('ORBIT_GATEWAY_IDENTITY'),
        'timeout' => (int) env('ORBIT_GATEWAY_TIMEOUT', 30),
    ],
];
