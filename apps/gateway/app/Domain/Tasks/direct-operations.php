<?php

declare(strict_types=1);

use App\Domain\Tasks\DependencyMergeProjection;
use App\Domain\Tasks\TaskParkProjection;

return ['tasks.dependency_merge' => [
    'workflow' => 'operation-dependency-merge', 'projection' => DependencyMergeProjection::class,
    'private_material' => ['token' => 'github.dependency_merge'],
], 'tasks.park' => [
    'workflow' => 'operation-task-park',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_park_writer',
    'projection' => TaskParkProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api'],
]];
