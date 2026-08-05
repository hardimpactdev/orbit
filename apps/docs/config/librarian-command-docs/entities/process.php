<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
    ],
    'optional' => [
        'command' => 'string',
        'crash_notification' => 'string',
        'instance' => 'string|null',
        'key' => 'string',
        'label' => 'string|null',
        'last_event' => 'object|null',
        'project' => 'string|null',
        'restart_policy' => 'string',
        'runtime' => 'string',
        'runtime_unit' => 'string',
        'status' => 'string',
        'workspace' => 'string|null',
    ],
];
