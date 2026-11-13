<?php

declare(strict_types=1);

return [
    'actions' => ['attach', 'clone', 'deploy', 'detach', 'logs', 'migrate', 'register', 'rename', 'resolve', 'restore', 'rollback', 'setup', 'sleep', 'transfer', 'wake'],
    'noun_endings' => [],
    'route_exceptions' => ['instance:resolve', 'instance:dependencies:show'],
];
