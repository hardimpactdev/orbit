<?php

declare(strict_types=1);

return [
    'ssh_public_key' => env('ORBIT_SWAMP_SSH_PUBLIC_KEY', ''),
    'address' => env('ORBIT_SWAMP_ADDRESS', 'swamp.orbit'),
    'proof_club_api_key' => env('ORBIT_SWAMP_PROOF_CLUB_API_KEY', ''),
    'read_token' => env('ORBIT_SWAMP_READ_TOKEN', ''),
    'run_token' => env('ORBIT_SWAMP_RUN_TOKEN', ''),
    'ca_path' => env('ORBIT_SWAMP_CA_PATH', rtrim((string) env('ORBIT_HOME', getenv('HOME').'/.orbit'), '/').'/ca/root.pem'),
];
