<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessOperationExecution;

return ['process.instance_runtime_ready' => ProcessInstanceOperation::class, 'process.runtime_ready' => ProcessOperationExecution::class];
