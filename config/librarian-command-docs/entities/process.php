<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
    ],
    'optional' => [
        'app' => 'string',
        'command' => 'string',
        'crash_notification' => 'string',
        'last_event' => 'object|null',
        'restart_policy' => 'string',
        'runtime' => 'string',
        'runtime_unit' => 'string',
    ],
];
