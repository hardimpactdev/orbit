<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Immutable image intent at the run start and one terminal projection from Swamp's run data. No host calls or progress callbacks. */
final readonly class TaskVmImageProjection implements DirectOperationProjection
{
    /** The workflow's steps in run order, as the chart names them, with the build step each one records. */
    public const array Steps = [
        'Launch the builder' => TaskVmImageBuildStep::Launch,
        'Wait for the builder' => TaskVmImageBuildStep::AwaitBoot,
        'Run the task image phases' => TaskVmImageBuildStep::Recipe,
        'Clean and stop' => TaskVmImageBuildStep::Clean,
        'Publish the candidate' => TaskVmImageBuildStep::Publish,
        'Smoke the candidate' => TaskVmImageBuildStep::Smoke,
        'Promote the image' => TaskVmImageBuildStep::Promote,
    ];

    /** The end check: the publish step holds the fingerprint, and a succeeded run has passed every later step. */
    public const string EndCheck = 'Publish the candidate';

    /** Admission: the attempt must own its build and its recipe must still be current when the run starts. */
    public function input(Operation $operation): array
    {
        $build = $this->build($operation);
        app(RecipeImageBuildSteps::class)->assertCurrent($build);

        return ['intent' => Arr::except($operation->input, ['caller_node_id']), 'execution_secrets' => []];
    }

    private function build(Operation $operation): TaskVmImageBuild
    {
        $build = TaskVmImageBuild::query()->findOrFail($operation->target_id);
        $input = $operation->input;
        if ($operation->kind !== 'task_vm.image_build' || $operation->target_type !== 'task_vm_image'
            || $build->host_node_id !== $operation->node_id || ($input['host_node_id'] ?? null) !== $operation->node_id
            || ($input['build_id'] ?? null) !== $build->id || ($input['attempt'] ?? null) !== $build->attempt
            || ($input['identity'] ?? null) !== $build->recipe_identity || ($input['generation'] ?? null) !== $build->recipe_generation
            || $build->cleanup_started_at !== null) {
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
            $native = ValidatedData::object($data['result'] ?? []);
            $fingerprint = self::fingerprint($native);
            $promoted = NativeRunResult::stepStatus($native, 'Promote the image') === 'succeeded';
            // The seed check skips the promote of an image without a seed. The run succeeds and the candidate stays.
            $kept = NativeRunResult::stepStatus($native, 'Promote the image') === 'skipped' && NativeRunResult::stepStatus($native, 'Check the seed') === 'succeeded';
            if (! $status->terminal()) {
                throw new ResourceOperationException('operation.result_invalid', 'The image result needs a terminal native outcome.', 422);
            }
            $code = in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true) ? $this->errorCode($data['failure'] ?? null) : null;
            $result = match (true) {
                $code !== null => ['error_code' => $code],
                $status === OperationStatus::Succeeded => ['ready' => $promoted, 'promoted' => $promoted, 'fingerprint' => $fingerprint],
                default => [],
            };
            if ($status === OperationStatus::Succeeded && ($fingerprint === null || ! ($promoted || $kept))) {
                throw new ResourceOperationException('operation.result_invalid', 'A succeeded image run needs its published fingerprint and a promoted or kept candidate.', 422);
            }
            if (! $operation->status->terminal()) {
                $this->record($build, $operation, $native, $fingerprint, $promoted);
            }

            return app(DirectOperation::class)->project($operation, [...$data, 'error_code' => $code], $result);
        });
    }

    /** `incus publish` prints "Instance published with fingerprint: <sha256>"; the publish step's output is the only place it lives.
     * @param  array<string,mixed>  $native
     */
    public static function fingerprint(array $native): ?string
    {
        $steps = is_array($native['steps'] ?? null) ? $native['steps'] : [];
        $stdout = is_array($steps[self::EndCheck] ?? null) ? ($steps[self::EndCheck]['stdout'] ?? null) : null;

        return is_string($stdout) && preg_match('/fingerprint: ([a-f0-9]{64})\b/', $stdout, $match) === 1 ? $match[1] : null;
    }

    /** The failed step names its stage's code; a step of a loop carries its item or index after the name. */
    private function errorCode(mixed $failure): string
    {
        $step = is_array($failure) && is_string($failure['failed_step'] ?? null) ? $failure['failed_step'] : '';

        return match (true) {
            str_starts_with($step, 'Wait for the builder') => TaskVmError::BootstrapTimeout->value,
            str_starts_with($step, 'Launch the builder') => TaskVmError::BootstrapFailed->value,
            str_starts_with($step, 'Remove builder and smoke') => 'task_vm.image_cleanup_failed',
            default => 'task_vm.image_build_failed',
        };
    }

    /** Build steps from the run's step statuses. A loop's steps start with the chart's name.
     * @param  array<string,mixed>  $native
     */
    private function record(TaskVmImageBuild $build, Operation $operation, array $native, ?string $fingerprint, bool $promoted): void
    {
        $statuses = is_array($native['steps'] ?? null) ? $native['steps'] : [];
        $entries = [];
        foreach (self::Steps as $name => $step) {
            $reached = array_filter(array_keys($statuses), fn (int|string $key): bool => str_starts_with((string) $key, $name)
                && NativeRunResult::stepStatus($native, (string) $key) !== 'skipped');
            if ($reached === []) {
                continue;
            }
            $program = $step === TaskVmImageBuildStep::Recipe ? 'base:phases' : null;
            $entries[$step->value.':'.$program] = ['step' => $step->value, 'program' => $program, 'started_at' => null, 'seconds' => null, 'error' => null];
        }
        // A run that ended before its first stage keeps the build's recorded steps.
        $last = end($entries);
        $changes = $last === false ? [] : ['steps' => array_values($entries), 'current_step' => TaskVmImageBuildStep::from($last['step']),
            'current_program' => $last['program']];
        if ($fingerprint !== null) {
            $changes['image_fingerprint'] = $fingerprint;
            $changes['images'] = ['base' => ['project' => ValidatedData::string($operation->input['project']),
                'alias' => ValidatedData::string($operation->input['candidate_alias']), 'fingerprint' => $fingerprint, 'type' => 'virtual-machine']];
        }
        if ($promoted && $fingerprint !== null) {
            $changes['promoted_at'] = $build->promoted_at ?? now();
        }
        $build->forceFill($changes)->save();
    }

    /** Project terminal changes, including shared-reader corrections after Gateway expiry. */
    public function changed(Operation $operation): void
    {
        if ($operation->wasChanged('status') && in_array($operation->status, [OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Interrupted], true)) {
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
        if ($status === OperationStatus::Succeeded && (($operation->result['fingerprint'] ?? null) !== $build->image_fingerprint
            || (($operation->result['promoted'] ?? null) === true && $build->promoted_at === null))) {
            return;
        }
        $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('attempt', $input['attempt'])
            ->whereIn('state', $status === OperationStatus::Succeeded
                ? [TaskVmImageBuildState::Running, TaskVmImageBuildState::Failed] : [TaskVmImageBuildState::Running])->whereNull('cleanup_started_at')
            ->update(['state' => $status === OperationStatus::Succeeded ? TaskVmImageBuildState::Succeeded : TaskVmImageBuildState::Failed]);
        if ($changed === 1) {
            $steps = $build->steps;
            foreach ($steps as &$entry) {
                if ($entry['step'] === $build->current_step?->value && $entry['program'] === $build->current_program) {
                    $entry['error'] = $status === OperationStatus::Succeeded ? null : ($errorCode ?? 'operation.cancelled');
                }
            }
            unset($entry);
            $build->update(['steps' => $steps]);
            if ($status === OperationStatus::Succeeded) {
                return;
            }
            DB::afterCommit(static fn () => app(RecipeMaintenanceAlerts::class)->halted(
                "Image build [{$build->id}] failed on host [{$build->host_node_id}]. Inspect the Operation and native cleanup outcome, then start a fresh attempt.",
                $build->id, incident: RecipeMaintenanceAlerts::imageIncident($build)));
        }
    }
}
