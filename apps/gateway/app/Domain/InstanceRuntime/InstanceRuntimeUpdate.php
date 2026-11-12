<?php

declare(strict_types=1);

namespace App\Domain\InstanceRuntime;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceFolderPlan;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** One running Instance's private Node store; never a Process action or fleet sweep. */
final readonly class InstanceRuntimeUpdate implements DirectOperationProjection
{
    public const string Kind = 'instance.runtime_update';

    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay(self::Kind)) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $runtime = $instance->runtime()->first();
            if ($runtime === null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Runtime update requires a container Instance.', 409);
            }
            if ($instance->placementEnvironment() !== 'development') {
                throw new ResourceOperationException('instance.host_unavailable', 'Runtime update requires a development Instance on an app-dev Node.', 409);
            }
            if ($instance->status !== InstanceState::Active
                || Operation::query()->where('target_type', 'process')->whereIn('target_id', $instance->processes()->select('id'))
                    ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance has unfinished lifecycle work.', 409);
            }
            if ($runtime->power_state !== InstanceRuntimePowerState::Running) {
                throw new ResourceOperationException('process.target_inactive', 'The container is stopped; runtime update does not start it.', 409);
            }

            $folders = app(RecipeImageBuilds::class)->currentRecipe()->instances
                ?? throw new ResourceOperationException('instance.host_unavailable', 'The recipe holds no shared Instance folders.', 409);
            $expected = InstanceFolderPlan::for($folders)['runtime'];

            return app(StartOperationAction::class)->execute(self::Kind, 'instance', $instance->id, $instance->node_id,
                ['container' => app(InstanceContainer::class)->intent($instance, $runtime),
                    'runtime_update' => ['path' => $expected['path'], 'version' => $expected['version']]], $caller, $requestId,
                lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);

        return ['intent' => [...$operation->input,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];

        return app(DirectOperation::class)->project($operation,
            [...$data, 'error_code' => $result['error_code'] ?? $data['error_code'] ?? null], $result);
    }
}
