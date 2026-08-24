<?php

declare(strict_types=1);

return [
    'gateway' => [
        'url' => env('ORBIT_GATEWAY_URL', 'https://10.6.0.2'),
        'ca_pem_path' => env('ORBIT_GATEWAY_CA_PEM'),
    ],
];
