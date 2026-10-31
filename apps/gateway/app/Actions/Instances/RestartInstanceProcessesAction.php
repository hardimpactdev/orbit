<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Processes\ProcessInstanceOperation;
use App\Models\Instance;
use App\Models\Operation;

/** Admit one native restart plan. No Gateway runtime calls or member Operations. */
final readonly class RestartInstanceProcessesAction
{
    public function __construct(private ProcessInstanceOperation $runtime) {}

    public function execute(Instance $instance, ?string $requestId = null): Operation
    {
        return $this->runtime->execute($instance, 'process.restart_instance', $requestId);
    }
}
