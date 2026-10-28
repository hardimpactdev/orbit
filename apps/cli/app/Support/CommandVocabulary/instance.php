<?php

declare(strict_types=1);

return [
    'actions' => ['clone', 'deploy', 'logs', 'register', 'rename', 'resolve', 'rollback', 'setup', 'transfer'],
    'noun_endings' => [],
    'route_exceptions' => ['instance:resolve', 'instance:dependencies:show'],
];
