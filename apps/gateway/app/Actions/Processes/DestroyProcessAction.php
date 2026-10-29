<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\RouteCustomProxy;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class DestroyProcessAction
{
    private ProcessRuntimeLease $lease;

    /** @param iterable<ProcessRemovalGuard> $guards */
    public function __construct(
        #[Tag(ProcessRemovalGuard::class)] private iterable $guards,
        ?ProcessRuntimeLease $lease = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
    }

    /** `$removedByOwningRole` is only for the feature that owns this Process. */
    public function execute(#[SensitiveParameter] Process $process, bool $removedByOwningRole = false): Process
    {
        $operation = app(AwaitOperation::class)->execute($this->admit($process, $removedByOwningRole));
        if ($operation->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException($operation->error_code ?? 'operation.failed', 'The runtime Operation did not succeed.', 409);
        }

        return $process;
    }

    public function admit(Process $process, bool $removedByOwningRole = false, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return DB::transaction(fn (): Operation => $this->lease->run($process, function (Process $fresh) use ($removedByOwningRole, $caller, $requestId): Operation {
            if (! $removedByOwningRole) {
                foreach ($this->guards as $guard) {
                    $guard->assertRemovable($fresh);
                }
            }

            if (RouteCustomProxy::query()->where('process_id', $fresh->id)->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'process.has_routes',
                    message: "Process [{$fresh->name}] is still targeted by a custom proxy Route.",
                    status: 409,
                );
            }

            return app(ProcessOperationAdmission::class)->execute($fresh, 'destroy', caller: $caller, requestId: $requestId);
        }));
    }
}
