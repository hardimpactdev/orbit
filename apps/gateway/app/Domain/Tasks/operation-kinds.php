<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskAnalysisOperation;
use App\Domain\Tasks\TaskCheckOperation;
use App\Domain\Tasks\TaskParkOperation;
use App\Domain\Tasks\TaskProvisionOperation;
use App\Domain\Tasks\TaskPublishOperation;
use App\Domain\Tasks\TaskScriptOperation;
use App\Domain\Tasks\TaskTurnOperation;
use App\Domain\Tasks\TaskWorkerOperation;

return [
    OperationKinds::TasksPublish => TaskPublishOperation::class,
    OperationKinds::TasksScript => TaskScriptOperation::class,
    OperationKinds::TasksCheck => TaskCheckOperation::class,
    OperationKinds::TasksAnalyze => TaskAnalysisOperation::class,
    OperationKinds::TasksProvision => TaskProvisionOperation::class,
    OperationKinds::TasksTurn => TaskTurnOperation::class,
    OperationKinds::TasksWorker => TaskWorkerOperation::class,
    OperationKinds::TasksPark => TaskParkOperation::class,
];
