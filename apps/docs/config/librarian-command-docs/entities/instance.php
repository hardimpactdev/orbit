<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'app' => 'string',
    ],
    'optional' => [
        'adopted' => 'bool',
        'deploy_warmup_paths' => 'array',
        'domain' => 'string|null',
        'driver' => 'string',
        'driver_config' => 'object|array',
        'environment' => 'string|null',
        'latest_deployment_run_id' => 'int|null',
        'latest_deployment_status' => 'string|null',
        'node' => 'string|object|null',
        'path' => 'string|null',
        'root' => 'string|null',
        'runtime' => 'object',
        'url' => 'string|null',
        'worker_config' => 'object|null',
        'worker_enabled' => 'bool',
    ],
];
