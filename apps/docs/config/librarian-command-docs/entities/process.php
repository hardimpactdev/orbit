<?php

declare(strict_types=1);

return [
    'required' => [
        'app' => 'string|null',
        'instance' => 'string|null',
        'name' => 'string',
        'node' => 'string',
        'workspace' => 'string|null',
    ],
    'optional' => [
        'command' => 'string',
        'crash_notification' => 'string',
        'key' => 'string',
        'label' => 'string|null',
        'last_event' => 'object|null',
        'restart_policy' => 'string',
        'runtime' => 'string',
        'runtime_unit' => 'string',
        'service' => 'object|null',
        'status' => 'string',
        'tool' => 'string|null',
    ],
];
