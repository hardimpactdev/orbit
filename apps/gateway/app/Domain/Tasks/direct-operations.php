<?php

declare(strict_types=1);

use App\Domain\Tasks\DependencyMergeProjection;
use App\Domain\Tasks\TaskAnalysisProjection;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskParkProjection;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\Tasks\TaskPublishProjection;
use App\Domain\Tasks\TaskScriptProjection;
use App\Domain\Tasks\TaskTurnProjection;

$vmProvision = (require __DIR__.'/../TaskVms/direct-operations.php')['task_vm.provision'];

return ['tasks.provision' => [
    'workflow_inputs' => true,
    'workflow' => 'operation-task-provision', 'projection' => TaskProvisionProjection::class,
    'private_material' => ['git_read' => 'instance.lifecycle_git', ...$vmProvision['private_material']],
    'secret_outputs' => $vmProvision['secret_outputs'],
], 'tasks.publish' => ['workflow' => 'operation-task-publish', 'projection' => TaskPublishProjection::class, 'private_material' => ['credentials' => 'github.task_repository']], 'tasks.analyze' => ['workflow' => 'operation-task-analyze', 'projection' => TaskAnalysisProjection::class], 'tasks.check' => ['workflow' => 'operation-task-check', 'projection' => TaskCheckProjection::class, 'private_material' => ['credentials' => 'github.task_repository']], 'tasks.script' => ['workflow' => 'operation-task-script', 'projection' => TaskScriptProjection::class], 'tasks.turn' => ['workflow' => 'operation-task-turn', 'projection' => TaskTurnProjection::class], 'tasks.dependency_merge' => [
    'workflow' => 'operation-dependency-merge', 'projection' => DependencyMergeProjection::class,
    'private_material' => ['token' => 'github.dependency_merge'],
], 'tasks.park' => [
    'workflow' => 'operation-task-park',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_park_writer',
    'projection' => TaskParkProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api'],
]];
