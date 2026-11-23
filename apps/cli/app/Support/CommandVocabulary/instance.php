<?php

declare(strict_types=1);

return [
    'actions' => ['attach', 'clone', 'complete', 'deploy', 'detach', 'logs', 'migrate', 'register', 'rename', 'resolve', 'restore', 'rollback', 'setup', 'sleep', 'switch', 'transfer', 'unregister', 'wake'],
    'noun_endings' => [],
    'route_exceptions' => ['instance:resolve', 'instance:dependencies:show'],
];
