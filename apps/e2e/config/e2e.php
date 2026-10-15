<?php

declare(strict_types=1);

return [
    'incus' => [
        'remote' => env('ORBIT_E2E_INCUS_REMOTE', 'local'),
        'project' => env('ORBIT_E2E_INCUS_PROJECT', 'default'),
        'storage_pool' => env('ORBIT_E2E_INCUS_STORAGE_POOL', 'orbit-e2e'),
        'cpu' => env('ORBIT_E2E_INCUS_CPU', '1'),
        // Each guest's memory comes from resources/topology-nodes.json; a value here overrides it for every guest.
        'memory' => env('ORBIT_E2E_INCUS_MEMORY'),
        'root_size' => env('ORBIT_E2E_INCUS_ROOT_SIZE', '16GiB'),
        'max_vms' => (int) env('ORBIT_E2E_INCUS_MAX_VMS', 24),
        'operation_id' => env('ORBIT_E2E_OPERATION_ID'),
        'ownership' => [
            'user.orbit.e2e.owner' => 'orbit-e2e',
        ],
    ],
    // Nested guests boot about three times slower than on a bare host.
    'readiness' => [
        'guest_seconds' => env('ORBIT_E2E_GUEST_READINESS_SECONDS', 900),
        'seconds' => env('ORBIT_E2E_READINESS_SECONDS', 600),
    ],
];
