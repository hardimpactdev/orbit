<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\PathProxy;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Process;
use App\Models\RouteCustomProxy;
use Illuminate\Container\Attributes\Tag;
use SensitiveParameter;

final readonly class DestroyProcessAction
{
    use MarksProcessRuntimeFailures;

    private ProcessRuntimeLease $lease;

    private RecordEventBroadcaster $broadcaster;

    /** @param iterable<ProcessRemovalGuard> $guards */
    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessTargetResolver $targets,
        #[Tag(ProcessRemovalGuard::class)] private iterable $guards,
        ?ProcessRuntimeLease $lease = null,
        ?RecordEventBroadcaster $broadcaster = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->broadcaster = $broadcaster ?? app(RecordEventBroadcaster::class);
    }

    /** `$removedByOwningRole` is true only when the analytics role removes its own `plausible` Process, or a Database server removes its own Process. */
    public function execute(#[SensitiveParameter] Process $process, bool $removedByOwningRole = false): Process
    {
        return $this->lease->run($process, function (Process $fresh) use ($removedByOwningRole): Process {
            PathProxy::assertProcessMutable($fresh);
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

            $this->targets->forRemoval($fresh);
            $fresh->update(['status' => LifecycleStatus::Removing, 'desired_state' => DesiredProcessState::Stopped]);

            try {
                $this->runtime->remove($fresh);
            } catch (ProcessOperationException $exception) {
                $this->markRuntimeFailed($fresh, $exception);

                throw $exception;
            }

            $fresh->delete();

            $this->broadcaster->broadcast(
                RecordEventType::ProcessDeleted,
                $fresh->id,
                ['id' => $fresh->id, 'name' => $fresh->name],
            );

            return $fresh;
        });
    }
}
