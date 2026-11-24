<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * The PHP symbol index of a running Laravel default and its seed, with nothing else of a deployment
 * (docs/reference/development-containers.md#prepare-the-index-of-a-running-default): the container's `php_index` and `seed` phases
 * only. No fetch, checkout, clean or deploy step runs, so the default's working tree, its untracked files included, stays as it is.
 * The seed is retaken only when the index changed.
 */
final readonly class PhpSymbolIndexRefresh implements DirectOperationProjection
{
    public const string Kind = 'instance.php_index';

    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay(self::Kind)) !== null) {
            return $replay;
        }

        // The seed's agent folder: the Project's instructions are read here, before the transaction
        // (docs/reference/development-containers.md#agent-instructions).
        $agentFolder = app(InstanceContainer::class)->seedAgentFolder($instance->project()->firstOrFail());

        return DB::transaction(function () use ($instance, $caller, $requestId, $agentFolder): Operation {
            $instance = Instance::query()->with(['node.roles', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $runtime = $instance->runtime()->first();
            // A host checkout has no container and no seed to hold an index.
            if ($runtime === null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'The PHP symbol index needs a container default with a seed.', 409);
            }
            if (PhpSymbolIndex::intent($instance) === null) {
                throw new ResourceOperationException('instance.php_index_ineligible',
                    'Only a Laravel default on an active app-dev Node prepares the PHP symbol index.', 409);
            }
            if ($instance->status !== InstanceState::Active || $runtime->seed_commit === null
                || Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)
                    ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance has unfinished lifecycle work.', 409);
            }
            if ($runtime->power_state !== InstanceRuntimePowerState::Running) {
                throw new ResourceOperationException('process.target_inactive', 'The container is stopped; the index refresh does not start it.', 409);
            }

            // A default in the nested project, a nested-fleet default or the proof default (whose intent has `fleet` or `seed_hold`): its
            // seed holds an inner fleet or is held against seed copies, and only its deployment keeps those rules (the fleet check, the
            // Club key refusal, the seed hold and `seed_taken_at`), so this run takes no such seed.
            if ($runtime->incus_project === InstancePlacement::NestedProject) {
                throw new ResourceOperationException('instance.php_index_unsupported',
                    'A nested-fleet or proof default prepares its PHP symbol index only in its deployment.', 409);
            }

            // The seed that clones copy: the run refuses with `instance.php_index_seed_moved` before any snapshot when the container's
            // HEAD is another commit, as after a failed deployment, since its seed phase would retake `seed-<HEAD>` and delete this one.
            return app(StartOperationAction::class)->execute(self::Kind, 'instance', $instance->id, $instance->node_id,
                ['container' => InstanceDeploymentIntent::container($instance, $runtime, $agentFolder), 'seed_commit' => $runtime->seed_commit], $caller, $requestId,
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
