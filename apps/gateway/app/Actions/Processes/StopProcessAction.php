<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Processes\ProcessOperationAdmission;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;

final readonly class StopProcessAction
{
    public function admit(Process $process, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return app(ProcessOperationAdmission::class)->execute($process, 'stop', caller: $caller, requestId: $requestId);
    }
}
