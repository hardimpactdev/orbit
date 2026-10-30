<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Operation;
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
    ];

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function admitImage(string $kind, int $buildId, int $nodeId, array $input): array
    {
        $build = TaskVmImageBuild::query()->findOrFail($buildId);
        $host = app(TaskVmSettings::class)->host($build->host_node_id);
        $fingerprint = $build->images['base']['fingerprint'] ?? null;
        if ($nodeId !== $build->host_node_id || ($input['build_id'] ?? null) !== $build->id
            || ($input['attempt'] ?? null) !== $build->attempt || $build->state !== TaskVmImageBuildState::Running
            || $build->cleanup_started_at !== null || ($input['phase'] ?? null) !== 'topology-seed'
            || ($input['effect_key'] ?? null) !== ($kind === 'task_vm.image_seed' ? 'topology-seed' : 'finish-seed')
            || ! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The seed must belong to this active image attempt.', 422);
        }
        $expected = match ($kind) {
            'task_vm.image_finish_seed' => ['location' => ['project' => $host->project, 'pool' => $host->pool], 'operation' => ['kind' => 'finish_seed', 'fingerprint' => $fingerprint]],
            'task_vm.image_seed' => ['project' => $host->project, 'pool' => $host->pool, 'vm' => $build->builder_vm_name.'-smoke', 'size' => TaskVmVolumes::SeedSize, 'fingerprint' => $fingerprint],
            default => throw new ResourceOperationException('operation.execution_forbidden', 'The image action is not declared.', 422),
        };
        if (Arr::sortRecursive(Arr::only($input, array_keys($expected))) !== Arr::sortRecursive($expected)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The seed does not match the admitted image candidate.', 422);
        }
        app(RecipeImageBuildSteps::class)->assertCurrent($build);

        return [...$input, 'agent_sha256' => app(AgentDelivery::class)->pin($build->hostNode)];
    }

    public function input(Operation $operation): array
    {
        $pin = $operation->input['agent_sha256'] ?? null;
        $seed = isset(self::ImageFields[$operation->kind]);
        if ((! $seed && ! in_array($operation->kind, self::Kinds, true)) || $operation->target_type !== ($seed ? 'task_vm_image' : 'task_vm')
            || $operation->node_id === null || ($operation->input[$seed ? 'build_id' : 'task_vm_id'] ?? null) !== $operation->target_id
            || ! is_string($pin) || preg_match('/\A[a-f0-9]{64}\z/D', $pin) !== 1) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Incus intent has no admitted VM and agent digest.', 422);
        }

        return ['agent_sha256' => $pin, 'intent' => Arr::only($operation->input, $seed ? ['build_id', 'attempt', ...self::ImageFields[$operation->kind]] : ['task_vm_id', 'vm', 'operation'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = ValidatedData::object($data['result'] ?? []);
        $valid = match ($status) {
            OperationStatus::Succeeded => $result === ['ready' => true] && $code === null,
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
