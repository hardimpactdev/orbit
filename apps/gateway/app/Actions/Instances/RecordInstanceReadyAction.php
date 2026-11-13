<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceLifecycleState;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The ready mark of a container clone's create run (docs/reference/operations.md#ready-mark-of-a-clone-create). It records the T3 server,
 * the running runtime, the workspace baseline and `ready_at` while the run still works. The vault and Node reads come before the
 * transaction, so no lock waits on the network. A repeat with the same server changes nothing and answers the same time.
 */
final readonly class RecordInstanceReadyAction
{
    public function __construct(private DirectOperation $direct, private DevelopmentInstanceCreateProjection $projection,
        private ContainerWorkspaceRead $workspace, private InstanceLifecycleContributions $contributions, private OperationStore $operations) {}

    /** @param array<string, mixed> $t3 the run's `clone-register` output */
    public function execute(Operation $operation, string $runId, array $t3): string
    {
        self::assertRunning($operation);
        $clone = ValidatedData::object(ValidatedData::object(ValidatedData::object($operation->input['direct_provisioning'])['container'])['clone']);
        $server = $this->projection->server(ValidatedData::object($clone['t3']), ['t3' => $t3]);
        $instance = app(InstanceLifecycleState::class)->instance($operation);
        $baseline = $instance->runtime()->first()?->workspace_baseline === null ? $this->workspace->baseline($instance) : null;

        return DB::transaction(function () use ($operation, $runId, $instance, $server, $baseline): string {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            self::assertRunning($operation);
            $this->direct->assertRun($operation, $runId);
            $this->contributions->projectCreate($instance, $server);
            $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->lockForUpdate()->firstOrFail();
            $runtime->fill(['power_state' => InstanceRuntimePowerState::Running]);
            if ($baseline !== null && $runtime->workspace_baseline === null) {
                // What the workspace holds at the mark is not work of its own (development-containers.md#workspace-work-state).
                $runtime->fill(['workspace_baseline' => $baseline, 'workspace_baseline_at' => now()]);
            }
            $runtime->save();
            $readyAt = $operation->state['ready_at'] ?? null;
            if (is_string($readyAt)) {
                return $readyAt;
            }
            $readyAt = now()->toIso8601ZuluString('microsecond');
            $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'ready_at' => $readyAt]]);

            return $readyAt;
        });
    }

    private static function assertRunning(Operation $operation): void
    {
        if ($operation->status->terminal()) {
            throw new ResourceOperationException('operation.finished', 'The Operation has ended.', 409);
        }
    }
}
