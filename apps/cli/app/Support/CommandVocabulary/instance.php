<?php

declare(strict_types=1);

return [
    'actions' => ['attach', 'clone', 'deploy', 'detach', 'logs', 'migrate', 'register', 'rename', 'resolve', 'rollback', 'setup', 'transfer'],
    'noun_endings' => [],
    'route_exceptions' => ['instance:resolve', 'instance:dependencies:show'],
];
