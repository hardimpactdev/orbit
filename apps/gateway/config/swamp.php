<?php

declare(strict_types=1);

$directWriters = json_decode((string) env('ORBIT_SWAMP_DIRECT_WRITERS', '{}'), true);

return [
    'address' => env('ORBIT_SWAMP_ADDRESS', 'swamp.orbit'),
    'read_token' => env('ORBIT_SWAMP_READ_TOKEN', ''),
    'run_token' => env('ORBIT_SWAMP_RUN_TOKEN', ''),
    'direct_writers' => is_array($directWriters) ? [...$directWriters, 'schedule.run' => 'direct'] : $directWriters,
    'route_publication_writers' => [
        'route.caddy_converge' => env('ORBIT_ROUTE_CADDY_WRITER', 'polled'),
        'route.private_dns_publish' => env('ORBIT_ROUTE_PRIVATE_DNS_WRITER', 'polled'),
    ],
    'ca_path' => env('ORBIT_SWAMP_CA_PATH', rtrim((string) env('ORBIT_HOME', getenv('HOME').'/.orbit'), '/').'/ca/root.pem'),
];
