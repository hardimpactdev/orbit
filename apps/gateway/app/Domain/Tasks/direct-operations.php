<?php

declare(strict_types=1);

use App\Domain\Tasks\DependencyMergeProjection;
use App\Domain\Tasks\TaskParkProjection;
use App\Domain\Tasks\TaskScriptProjection;
use App\Domain\Tasks\TaskTurnProjection;

return ['tasks.script' => ['workflow' => 'operation-task-script', 'projection' => TaskScriptProjection::class], 'tasks.turn' => ['workflow' => 'operation-task-turn', 'projection' => TaskTurnProjection::class], 'tasks.dependency_merge' => [
    'workflow' => 'operation-dependency-merge', 'projection' => DependencyMergeProjection::class,
    'private_material' => ['token' => 'github.dependency_merge'],
], 'tasks.park' => [
    'workflow' => 'operation-task-park',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_park_writer',
    'projection' => TaskParkProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api'],
]];
