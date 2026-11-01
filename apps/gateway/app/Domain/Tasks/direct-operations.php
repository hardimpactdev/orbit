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
    'workflow' => 'operation-task-provision', 'projection' => TaskProvisionProjection::class,
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'provision', 'step' => 'result'],
    'execution_copies' => [...$vmProvision['execution_copies'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-provision-git-[1-9][0-9]*$']],
], 'tasks.publish' => ['workflow' => 'operation-task-publish', 'projection' => TaskPublishProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'publish', 'step' => 'result'], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-publish-token-[a-f0-9-]{36}$']]], 'tasks.analyze' => ['workflow' => 'operation-task-analyze', 'projection' => TaskAnalysisProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'analysis', 'step' => 'result']], 'tasks.check' => ['workflow' => 'operation-task-check', 'projection' => TaskCheckProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'check', 'step' => 'result'],
    'scheduled_result' => ['workflow' => 'task-check-ci', 'workflow_id' => '4a2d9dd5-9f83-41d4-b8df-8e46367ed230',
        'terminal_result' => ['job' => 'checks', 'step' => 'result'], 'rows' => 'checks'], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-write-[a-f0-9-]{36}$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-check-read-[a-f0-9-]{36}$']]], 'tasks.script' => ['workflow' => 'operation-task-script', 'projection' => TaskScriptProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'script', 'step' => 'result']], 'tasks.turn' => ['workflow' => 'operation-task-turn', 'projection' => TaskTurnProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'turn', 'step' => 'result'], 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-pi-token-[1-9][0-9]*$']]], 'tasks.dependency_merge' => [
            'workflow' => 'operation-dependency-merge', 'projection' => DependencyMergeProjection::class,
            'private_material' => ['token' => 'github.dependency_merge'],
        ], 'tasks.park' => [
            'workflow' => 'operation-task-park',
            'projection' => TaskParkProjection::class,
            'workflow_inputs' => true,
            'terminal_result' => ['job' => 'park', 'step' => 'result'],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-upcloud-token$']],
        ]];
