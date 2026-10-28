<?php

declare(strict_types=1);

return [
    'actions' => ['archive', 'download', 'read', 'restore', 'restore-version', 'search', 'upload', 'versions', 'write'],
    'noun_endings' => [],
    'route_exceptions' => ['project:document:destroy', 'project:document:version:list'],
];
