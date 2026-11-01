<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Immutable image intent and native progress projection. No host calls or child proofs. */
final readonly class TaskVmImageProjection implements DirectOperationProjection
{
    public const array Phases = ['reset', 'launch', 'boot', 'apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi', 'prepare', 'clean', 'publish', 'smoke', 'seed', 'delete-smoke', 'finish-seed', 'promote', 'prune', 'complete'];

    public function input(Operation $operation): array
    {
        $build = $this->build($operation);
        $input = Arr::except($operation->input, ['caller_node_id', 'pi_artifact_file']);
        if (is_array($input['pi_artifact'] ?? null)) {
            $input['pi_artifact'] = Arr::only($input['pi_artifact'], ['sha256']);
        }

        $input['current'] = app(RecipeImageBuildSteps::class)->current($build);

        return $input;
    }

    private function build(Operation $operation): TaskVmImageBuild
    {
        $build = TaskVmImageBuild::query()->findOrFail($operation->target_id);
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.image_build' || $operation->target_type !== 'task_vm_image'
            || $build->host_node_id !== $operation->node_id || ($input['host_node_id'] ?? null) !== $operation->node_id
            || ($input['build_id'] ?? null) !== $build->id || ($input['attempt'] ?? null) !== $build->attempt
            || ($input['identity'] ?? null) !== $build->recipe_identity || ($input['generation'] ?? null) !== $build->recipe_generation
            || $build->cleanup_started_at !== null || ! is_string($input['agent_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $input['agent_sha256']) !== 1) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted image attempt no longer owns this build.', 422);
        }

        return $build;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $build = $this->build($operation);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $result = ValidatedData::object($data['result'] ?? []);
            $code = ValidatedData::nullableString($data['error_code'] ?? null);
            $phase = $result['phase'] ?? null;
            $fingerprint = $result['fingerprint'] ?? null;
            $hash = is_string($fingerprint) && preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) === 1;
            $valid = match ($status) {
                OperationStatus::Running => $code === null && in_array($phase, self::Phases, true)
                    && array_diff(array_keys($result), ['phase', 'completed', 'fingerprint']) === []
                    && (! isset($result['completed']) || $result['completed'] === true)
                    && ($fingerprint === null || ($phase === 'publish' && $hash)),
                OperationStatus::Succeeded => $code === null && count($result) === 2 && ($result['ready'] ?? null) === true
                    && $hash && $fingerprint === $build->image_fingerprint && $build->promoted_at !== null,
                OperationStatus::Failed => $code !== null && preg_match('/\A[a-z0-9_]+\.[a-z0-9_.-]{1,80}\z/D', $code) === 1 && $result === ['error_code' => $code],
                OperationStatus::Cancelled => $code === null && $result === [],
                default => false,
            };
            if (! $valid) {
                throw new ResourceOperationException('operation.result_invalid', 'The image result needs a bounded native phase or outcome.', 422);
            }
            if (in_array($status, [OperationStatus::Running, OperationStatus::Succeeded], true)) {
                app(RecipeImageBuildSteps::class)->assertCurrent($build);
            }
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $status === OperationStatus::Running) {
                $this->progress($build, $operation, ValidatedData::string($phase), $result);
            }
            if (! $terminal && $status === OperationStatus::Succeeded) {
                $this->finish($projected, $status, $code);
            }

            return $projected;
        });
    }

    /** @param array<string,mixed> $result */
    private function progress(TaskVmImageBuild $build, Operation $operation, string $phase, array $result): void
    {
        $phase = in_array($phase, ['seed', 'delete-smoke', 'finish-seed'], true) ? 'topology-seed' : $phase;
        $step = match ($phase) {
            'promote' => TaskVmImageBuildStep::Promote, 'prune' => TaskVmImageBuildStep::Prune,
            default => TaskVmImageBuildStep::Recipe,
        };
        $program = $step === TaskVmImageBuildStep::Recipe ? 'base:'.$phase : null;
        $steps = $build->steps;
        $index = array_find_key($steps, static fn (array $entry): bool => $entry['step'] === $step->value && $entry['program'] === $program) ?? count($steps);
        $entry = $steps[$index] ?? ['step' => $step->value, 'program' => $program, 'started_at' => now()->toISOString(), 'seconds' => null, 'error' => null];
        if (($result['completed'] ?? false) === true) {
            $entry['seconds'] = max(0, now()->getTimestamp() - Carbon::parse($entry['started_at'])->getTimestamp());
        }
        $steps[$index] = $entry;
        if (isset($result['fingerprint'])) {
            $build->image_fingerprint = ValidatedData::string($result['fingerprint']);
            $build->images = ['base' => ['project' => ValidatedData::string($operation->input['project']),
                'alias' => ValidatedData::string($operation->input['candidate_alias']), 'fingerprint' => $build->image_fingerprint, 'type' => 'virtual-machine']];
        }
        if ($phase === 'promote' && ($result['completed'] ?? false) === true) {
            $build->promoted_at ??= now();
        }
        $build->forceFill(['steps' => $steps, 'current_step' => $step, 'current_program' => $program])->save();
    }

    /** Failure can be recorded by the Gateway timeout/cancellation path before a native callback. */
    public function changed(Operation $operation): void
    {
        if ($operation->wasChanged('status') && in_array($operation->status, [OperationStatus::Failed, OperationStatus::Cancelled], true)) {
            $this->finish($operation, $operation->status, $operation->error_code);
        }
    }

    private function finish(Operation $operation, OperationStatus $status, ?string $errorCode): void
    {
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.image_build' || $operation->target_type !== 'task_vm_image'
            || ($input['build_id'] ?? null) !== $operation->target_id || ($input['host_node_id'] ?? null) !== $operation->node_id) {
            return;
        }
        $build = TaskVmImageBuild::query()->whereKey($operation->target_id)->where('host_node_id', $operation->node_id)
            ->where('attempt', $input['attempt'] ?? null)->where('recipe_identity', $input['identity'] ?? null)
            ->where('recipe_generation', $input['generation'] ?? null)->first();
        if ($build === null) {
            return;
        }
        $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('attempt', $operation->input['attempt'])
            ->where('state', TaskVmImageBuildState::Running)->whereNull('cleanup_started_at')
            ->update(['state' => $status === OperationStatus::Succeeded ? TaskVmImageBuildState::Succeeded : TaskVmImageBuildState::Failed]);
        if ($changed === 1 && $status !== OperationStatus::Succeeded) {
            $steps = $build->steps;
            foreach ($steps as &$entry) {
                if ($entry['step'] === $build->current_step?->value && $entry['program'] === $build->current_program) {
                    $entry['error'] = $errorCode ?? 'operation.cancelled';
                }
            }
            unset($entry);
            $build->update(['steps' => $steps]);
            DB::afterCommit(static fn () => app(RecipeMaintenanceAlerts::class)->halted(
                "Image build [{$build->id}] failed on host [{$build->host_node_id}]. Inspect the Operation and native cleanup outcome, then start a fresh attempt.",
                $build->id, incident: RecipeMaintenanceAlerts::imageIncident($build)));
        }
    }
}
