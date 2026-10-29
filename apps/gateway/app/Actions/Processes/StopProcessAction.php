<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class StopProcessAction
{
    private ProcessRuntimeLease $lease;

    public function __construct(
        ?ProcessRuntimeLease $lease = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
    }

    public function execute(#[SensitiveParameter] Process $process): Process
    {
        $operation = app(AwaitOperation::class)->execute($this->admit($process));
        if ($operation->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException($operation->error_code ?? 'process.operation_failed', 'The Process Operation did not succeed.', 409);
        }

        return $process->refresh();
    }

    public function admit(Process $process, ?Node $caller = null, ?string $requestId = null): Operation
    {
        $admit = fn (): Operation => DB::transaction(fn (): Operation => $this->lease->run($process, function (Process $fresh) use ($caller, $requestId): Operation {
            return app(ProcessOperationAdmission::class)->execute($fresh, 'stop', caller: $caller, requestId: $requestId);
        }));

        return $admit();
    }
}
