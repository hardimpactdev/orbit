<?php

declare(strict_types=1);

$directWriters = json_decode((string) env('ORBIT_SWAMP_DIRECT_WRITERS', '{}'), true);

return [
    'address' => env('ORBIT_SWAMP_ADDRESS', 'swamp.orbit'),
    'read_token' => env('ORBIT_SWAMP_READ_TOKEN', ''),
    'run_token' => env('ORBIT_SWAMP_RUN_TOKEN', ''),
    'direct_writers' => $directWriters,
    'ca_path' => env('ORBIT_SWAMP_CA_PATH', rtrim((string) env('ORBIT_HOME', getenv('HOME').'/.orbit'), '/').'/ca/root.pem'),
];
