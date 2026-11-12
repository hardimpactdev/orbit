<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Instances\InstanceNodeServiceTargets;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;

final readonly class StopProcessAction
{
    public function admit(Process $process, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return app(ProcessRuntimeLease::class)->run($process, function (Process $fresh) use ($caller, $requestId): Operation {
            InstanceNodeServiceTargets::assertEndpointPreserved($fresh);

            return app(ProcessOperationAdmission::class)->execute($fresh, 'stop', caller: $caller, requestId: $requestId);
        });
    }
}
