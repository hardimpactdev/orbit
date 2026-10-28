<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\DependencyMergeCompletion;
use App\Domain\Tasks\TaskAnalysisOperation;
use App\Domain\Tasks\TaskCheckOperation;
use App\Domain\Tasks\TaskParkOperation;
use App\Domain\Tasks\TaskProvisionOperation;
use App\Domain\Tasks\TaskPublishOperation;
use App\Domain\Tasks\TaskScriptOperation;
use App\Domain\Tasks\TaskTurnOperation;
use App\Domain\Tasks\TaskWorkerOperation;

return [
    'tasks.dependency_merge' => [
        'executor' => 'swamp', 'completion' => DependencyMergeCompletion::class,
        'runs' => [[
            'transport' => 'gateway', 'name' => 'github.dependency-squash',
            'input_fields' => ['project_id', 'group_id', 'publish_operation_id', 'repository', 'default_branch', 'smoke_workflow', 'pr_number', 'head_sha', 'token'],
            'input_values' => [], 'secrets' => ['token' => 'github.dependency_merge'],
        ]],
        'completion_contract' => ['step' => 'squash', 'transport' => 'gateway', 'name' => 'github.dependency-squash', 'result' => ['merged' => true]],
        'result_fields' => ['merged', 'head_sha', 'merge_sha', 'error_code'],
    ],
    OperationKinds::TasksPublish => TaskPublishOperation::class,
    OperationKinds::TasksScript => TaskScriptOperation::class,
    OperationKinds::TasksCheck => TaskCheckOperation::class,
    OperationKinds::TasksAnalyze => TaskAnalysisOperation::class,
    OperationKinds::TasksProvision => TaskProvisionOperation::class,
    OperationKinds::TasksTurn => TaskTurnOperation::class,
    OperationKinds::TasksWorker => TaskWorkerOperation::class,
    OperationKinds::TasksPark => TaskParkOperation::class,
];
