<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Infrastructure\TaskVms\RecipeImagePruning;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Safe admitted Node input and bounded result projection; no execution. */
final readonly class TaskVmIncusProjection implements DirectOperationProjection
{
    public const array Kinds = ['task_vm.incus_create', 'task_vm.incus_start', 'task_vm.incus_stop', 'task_vm.incus_delete'];

    public const array ImageFields = [
        'task_vm.image_finish_seed' => ['location', 'operation'],
        'task_vm.image_seed' => ['project', 'pool', 'vm', 'size', 'fingerprint'],
        'task_vm.image_metadata' => ['project', 'operation'],
        'task_vm.image_remove' => ['vm', 'operation'],
        'task_vm.image_publish' => ['project', 'operation'],
        'task_vm.image_launch' => ['vm', 'operation'],
        'task_vm.image_promote' => ['project', 'operation'],
        'task_vm.image_prune' => ['project', 'operation'],
    ];

    public const array CacheFields = ['task_vm.cache_initialize' => ['operation'], 'task_vm.cache_mount' => ['project', 'name']];

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function admitImage(string $kind, int $buildId, int $nodeId, array $input): array
    {
        $build = TaskVmImageBuild::query()->findOrFail($buildId);
        $host = app(TaskVmSettings::class)->host($build->host_node_id);
        $fingerprint = $build->images['base']['fingerprint'] ?? null;
        [$phase, $key] = match ($kind) {
            'task_vm.image_seed' => ['topology-seed', 'topology-seed'],
            'task_vm.image_finish_seed' => ['topology-seed', 'finish-seed'],
            'task_vm.image_metadata' => ['prepare', 'metadata'],
            'task_vm.image_publish' => ['publish', 'publish'],
            'task_vm.image_promote' => ['promote', 'promote'],
            'task_vm.image_prune' => ['prune', 'prune'],
            'task_vm.image_launch' => match ($input['effect_key'] ?? null) {
                'launch' => ['launch', 'launch'], 'launch-smoke' => ['smoke', 'launch-smoke'], 'seed-smoke-launch' => ['topology-seed', 'seed-smoke-launch'],
                default => throw new ResourceOperationException('operation.execution_forbidden', 'The image launch is not declared.', 422),
            },
            'task_vm.image_remove' => match ($input['effect_key'] ?? null) {
                'reset-builder' => ['reset', 'reset-builder'], 'reset-smoke' => ['reset', 'reset-smoke'],
                'delete-builder' => ['publish', 'delete-builder'], 'delete-smoke' => ['topology-seed', 'delete-smoke'],
                'reset-seed-smoke' => ['topology-seed', 'reset-seed-smoke'],
                default => throw new ResourceOperationException('operation.execution_forbidden', 'The image removal is not declared.', 422),
            },
            default => throw new ResourceOperationException('operation.execution_forbidden', 'The image action is not declared.', 422),
        };
        if ($nodeId !== $build->host_node_id || ($input['build_id'] ?? null) !== $build->id
            || ($input['attempt'] ?? null) !== $build->attempt || $build->state !== TaskVmImageBuildState::Running
            || $build->cleanup_started_at !== null || ($input['phase'] ?? null) !== $phase
            || ($input['effect_key'] ?? null) !== $key
            || ((in_array($kind, ['task_vm.image_seed', 'task_vm.image_finish_seed'], true) || ($kind === 'task_vm.image_launch' && $key !== 'launch')) && (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The effect must belong to this active image attempt.', 422);
        }
        if ($kind === 'task_vm.image_prune' && $build->promoted_at === null) {
            throw new ResourceOperationException('operation.completion_unproven', 'The image must be promoted before pruning.', 409);
        }
        $expected = match ($kind) {
            'task_vm.image_finish_seed' => ['location' => ['project' => $host->project, 'pool' => $host->pool], 'operation' => ['kind' => 'finish_seed', 'fingerprint' => $fingerprint]],
            'task_vm.image_seed' => ['project' => $host->project, 'pool' => $host->pool, 'vm' => $build->builder_vm_name.'-smoke', 'size' => TaskVmVolumes::SeedSize, 'fingerprint' => $fingerprint],
            'task_vm.image_metadata' => ['project' => $host->project, 'operation' => ['kind' => 'prepare_metadata', 'name' => $build->builder_vm_name]],
            'task_vm.image_prune' => app(RecipeImagePruning::class)->input($build),
            'task_vm.image_promote' => ['project' => $host->project, 'operation' => ['kind' => 'promote', 'alias' => $host->imageAlias, 'fingerprint' => $build->image_fingerprint]],
            'task_vm.image_publish' => ['project' => $host->project, 'operation' => ['kind' => 'publish', 'name' => $build->builder_vm_name,
                'alias' => $build->images['base']['alias'] ?? RecipeImageBuilds::alias($host->imageAlias, (string) $build->recipe_identity, 'base', $build->id),
                'properties' => ['orbit.recipe' => $build->recipe_identity, 'orbit.profile' => 'base']]],
            'task_vm.image_launch' => ['vm' => ['project' => $host->project, 'name' => $build->builder_vm_name.($key === 'launch' ? '' : '-smoke'), 'cidr' => $host->cidr],
                'operation' => ['kind' => 'create_image', 'image' => $key === 'launch' ? (Arr::get($input, 'operation.image') === TaskVmHost::SourceImage ? TaskVmHost::SourceImage : ($build->source_fingerprint ?? TaskVmHost::SourceImage)) : $fingerprint,
                    'gateway_public_key' => app(SshKeyProvider::class)->publicKey(), 'cpus' => $host->cpus, 'memory' => $host->memory, 'disk' => $host->disk, 'network' => $host->network, 'builder' => $key === 'launch']],
            'task_vm.image_remove' => ['vm' => ['project' => $host->project, 'name' => $build->builder_vm_name.(in_array($key, ['reset-builder', 'delete-builder'], true) ? '' : '-smoke'), 'cidr' => $host->cidr], 'operation' => ['kind' => 'remove_instance']],
        };
        if (in_array($kind, ['task_vm.image_promote', 'task_vm.image_prune'], true)) {
            $input += $expected;
        }
        if (Arr::sortRecursive(Arr::only($input, array_keys($expected))) !== Arr::sortRecursive($expected)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The effect does not match the admitted image attempt.', 422);
        }
        $eligibility = app(RecipeImageBuildSteps::class);
        if ($kind === 'task_vm.image_promote') {
            $eligibility->assertPromotion($build);
        } else {
            $eligibility->assertCurrent($build);
        }
        if ($kind === 'task_vm.image_launch') {
            if ($key === 'launch' && $build->source_alias === null) {
                $build->forceFill(['source_alias' => TaskVmHost::SourceImage,
                    'source_fingerprint' => app(IncusHost::class)->aliasTarget($host, $build->hostNode, TaskVmHost::SourceImage)])->save();
            }
            $input['launch_image'] = $key === 'launch' ? ($build->source_fingerprint ?? TaskVmHost::SourceImage) : $fingerprint;
        }

        return [...$input, 'agent_sha256' => app(AgentDelivery::class)->pin($build->hostNode)];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function admitCache(string $kind, int $vmId, int $nodeId, array $input): array
    {
        $vm = TaskVm::query()->findOrFail($vmId);
        if (! isset(self::CacheFields[$kind]) || $vm->provider !== TaskVmProviderKind::Incus
            || $vm->host_node_id !== $nodeId || $vm->state !== TaskVmState::Ready || $vm->name !== 'tvm-'.$vm->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Cache preparation requires its ready Incus VM and owning host.', 422);
        }
        $host = app(TaskVmSettings::class)->host($nodeId);
        $expected = $kind === 'task_vm.cache_initialize' ? ['effect_key' => 'cache-initialize']
            : ['effect_key' => 'cache-mount', 'project' => $host->project, 'name' => $vm->name];
        if (Arr::sortRecursive($input) !== Arr::sortRecursive($expected)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The cache effect must match its fixed owning VM intent.', 422);
        }

        return [...$input, 'task_vm_id' => $vm->id, ...($kind === 'task_vm.cache_initialize' ? ['operation' => 'initialize'] : []), 'agent_sha256' => app(AgentDelivery::class)->pin($vm->incusHostNode())];
    }

    public function input(Operation $operation): array
    {
        $pin = $operation->input['agent_sha256'] ?? null;
        $seed = isset(self::ImageFields[$operation->kind]);
        if ((! $seed && ! isset(self::CacheFields[$operation->kind]) && ! in_array($operation->kind, self::Kinds, true)) || $operation->target_type !== ($seed ? 'task_vm_image' : 'task_vm')
            || $operation->node_id === null || ($operation->input[$seed ? 'build_id' : 'task_vm_id'] ?? null) !== $operation->target_id
            || ! is_string($pin) || preg_match('/\A[a-f0-9]{64}\z/D', $pin) !== 1) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Incus intent has no admitted VM and agent digest.', 422);
        }

        $intent = Arr::only($operation->input, $seed ? ['build_id', 'attempt', ...self::ImageFields[$operation->kind]] : ['task_vm_id', ...(self::CacheFields[$operation->kind] ?? ['vm', 'operation'])]);
        if ($operation->kind === 'task_vm.image_launch') {
            $intent['operation'] = [...ValidatedData::object($intent['operation']), 'image' => ValidatedData::string($operation->input['launch_image'])];
        }

        return ['agent_sha256' => $pin, 'intent' => $intent];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = ValidatedData::object($data['result'] ?? []);
        $valid = match ($status) {
            OperationStatus::Succeeded => $code === null && (in_array($operation->kind, ['task_vm.image_publish', 'task_vm.image_promote'], true)
                ? count($result) === 2 && ($result['ready'] ?? null) === true && is_string($result['fingerprint'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $result['fingerprint']) === 1
                    && ($operation->kind !== 'task_vm.image_promote' || $result['fingerprint'] === Arr::get($operation->input, 'operation.fingerprint'))
                : $result === ['ready' => true]),
            OperationStatus::Failed => $code !== null && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) === 1 && $result === ['error_code' => $code],
            OperationStatus::Running, OperationStatus::Cancelled => $result === [] && $code === null,
            default => false,
        };
        if (! $valid) {
            throw new ResourceOperationException('operation.result_invalid', 'The Incus action needs a bounded successful result or refusal.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $result, $status): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result, str_replace('_', '-', substr($operation->kind, strlen('task_vm.'))));
            if (! $terminal && isset(self::ImageFields[$operation->kind]) && $status === OperationStatus::Succeeded) {
                app(TaskVmImageEffectCompletion::class)->complete($projected, OperationOutcome::succeeded($result));
            }

            return $projected;
        });
    }
}
