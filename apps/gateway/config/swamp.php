<?php

declare(strict_types=1);

return [
    'address' => env('ORBIT_SWAMP_ADDRESS', 'swamp.orbit'),
    'read_token' => env('ORBIT_SWAMP_READ_TOKEN', ''),
    'ca_path' => env('ORBIT_SWAMP_CA_PATH', rtrim((string) env('ORBIT_HOME', getenv('HOME').'/.orbit'), '/').'/ca/root.pem'),
];
