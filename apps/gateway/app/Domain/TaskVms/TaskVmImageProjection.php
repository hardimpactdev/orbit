<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\NativeRunTimestamp;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\RecipeImageBuildSteps;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Immutable image intent at the run start and one terminal projection from Swamp's run data. No host calls or progress callbacks. */
final readonly class TaskVmImageProjection implements DirectOperationProjection
{
    /** Workflow stages in run order; each native step is named after its stage, alone or with an action suffix. */
    public const array Phases = ['reset', 'launch', 'boot', 'apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi', 'prepare', 'clean', 'publish', 'smoke', 'seed', 'delete-smoke', 'finish-seed', 'promote', 'prune', 'complete'];

    /** The recipe phases whose start and completion reads give the phase seconds. */
    public const array RecipePhases = ['apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi'];

    /** Fixed captures for the declaration: the published fingerprint and each recipe phase's start and completion read.
     * @return array<string,array{job:string,step:string,field?:string}>
     */
    public static function captures(): array
    {
        $captures = ['fingerprint' => ['job' => 'build', 'step' => 'publish-reply', 'field' => 'fingerprint']];
        foreach (self::RecipePhases as $phase) {
            $name = str_replace('-', '_', $phase);
            $captures[$name.'_start'] = ['job' => 'build', 'step' => $phase.'-start'];
            $captures[$name.'_inspect'] = ['job' => 'build', 'step' => $phase.'-inspect'];
        }

        return $captures;
    }

    /** Admission: the attempt must own its build and its recipe must still be current when the run starts. */
    public function input(Operation $operation): array
    {
        $build = $this->build($operation);
        app(RecipeImageBuildSteps::class)->assertCurrent($build);
        $input = Arr::except($operation->input, ['caller_node_id']);
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($query) => $query
            ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        // The Pi build lives in the Gateway home; 209 fetches it with the same pinned SSH as every other step.
        $input['pi_source'] = app(SwampSshExecutionInput::class)->forNode($gateway);

        return ['intent' => $input, 'execution_secrets' => []];
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
            $native = ValidatedData::object($data['result'] ?? []);
            $fingerprint = $native['fingerprint'] ?? null;
            $fingerprint = is_string($fingerprint) && preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) === 1 ? $fingerprint : null;
            $promoted = NativeRunResult::stepStatus($native, 'promote-reply') === 'succeeded';
            if (! $status->terminal()) {
                throw new ResourceOperationException('operation.result_invalid', 'The image result needs a terminal native outcome.', 422);
            }
            $code = in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true) ? $this->errorCode($data['failure'] ?? null) : null;
            $result = match (true) {
                $code !== null => ['error_code' => $code],
                $status === OperationStatus::Succeeded => ['ready' => true, 'fingerprint' => $fingerprint],
                default => [],
            };
            if ($status === OperationStatus::Succeeded && ($fingerprint === null || ! $promoted)) {
                throw new ResourceOperationException('operation.result_invalid', 'A succeeded image run needs its published and promoted fingerprint.', 422);
            }
            if (! $operation->status->terminal()) {
                $this->record($build, $operation, $native, $fingerprint, $promoted);
            }

            return app(DirectOperation::class)->project($operation, [...$data, 'error_code' => $code], $result);
        });
    }

    /** A refused agent reply keeps its own public code; other failures take their stage's code. */
    private function errorCode(mixed $failure): string
    {
        $step = is_array($failure) && is_string($failure['failed_step'] ?? null) ? $failure['failed_step'] : '';
        $detail = is_array($failure) && is_string($failure['detail'] ?? null) ? trim($failure['detail']) : '';
        if ((str_ends_with($step, '-reply') || $step === 'plan') && preg_match('/\A[a-z0-9_]+\.[a-z0-9_.-]{1,80}\z/D', $detail) === 1) {
            return $detail;
        }

        return match (true) {
            str_starts_with($step, 'cleanup-') => 'task_vm.image_cleanup_failed',
            in_array($step, ['boot-wait-agent', 'smoke-wait-agent'], true) => TaskVmError::BootstrapTimeout->value,
            in_array($step, ['boot-cloud-init', 'boot-push-agent', 'smoke-cloud-init', 'smoke-push-agent'], true) => TaskVmError::BootstrapFailed->value,
            default => 'task_vm.image_build_failed',
        };
    }

    /** Build steps from the run's step statuses; seconds only where the run data shows a phase's start and end.
     * @param  array<string,mixed>  $native
     */
    private function record(TaskVmImageBuild $build, Operation $operation, array $native, ?string $fingerprint, bool $promoted): void
    {
        $statuses = is_array($native['steps'] ?? null) ? $native['steps'] : [];
        $entries = [];
        foreach (self::Phases as $phase) {
            $reached = array_filter(array_keys($statuses), fn (int|string $name): bool => $this->stage((string) $name) === $phase
                && NativeRunResult::stepStatus($native, (string) $name) !== 'skipped');
            if ($reached === []) {
                continue;
            }
            $program = in_array($phase, ['seed', 'delete-smoke', 'finish-seed'], true) ? 'topology-seed' : $phase;
            $step = match ($program) {
                'promote' => TaskVmImageBuildStep::Promote, 'prune' => TaskVmImageBuildStep::Prune, default => TaskVmImageBuildStep::Recipe,
            };
            $program = $step === TaskVmImageBuildStep::Recipe ? 'base:'.$program : null;
            [$startedAt, $seconds] = $this->timing($native, $phase);
            $entries[$step->value.':'.$program] ??= ['step' => $step->value, 'program' => $program, 'started_at' => $startedAt, 'seconds' => $seconds, 'error' => null];
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

    /** The longest stage name that is the step name or its prefix before an action. */
    private function stage(string $step): ?string
    {
        $match = null;
        foreach (self::Phases as $phase) {
            if (($step === $phase || str_starts_with($step, $phase.'-')) && strlen($phase) > strlen($match ?? '')) {
                $match = $phase;
            }
        }

        return $match;
    }

    /** @param array<string,mixed> $native
     * @return array{0:?string,1:?float}
     */
    private function timing(array $native, string $phase): array
    {
        if (! in_array($phase, self::RecipePhases, true)) {
            return [null, null];
        }
        $name = str_replace('-', '_', $phase);
        $start = is_array($native[$name.'_start'] ?? null) ? NativeRunTimestamp::parse($native[$name.'_start']['startedAt'] ?? null) : null;
        $end = is_array($native[$name.'_inspect'] ?? null) ? NativeRunTimestamp::parse($native[$name.'_inspect']['finishedAt'] ?? null) : null;

        return [$start?->toISOString(), $start !== null && $end !== null ? round(max(0.0, $start->diffInMilliseconds($end)) / 1000, 1) : null];
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
        if ($status === OperationStatus::Succeeded && (($operation->result['ready'] ?? null) !== true
            || ($operation->result['fingerprint'] ?? null) !== $build->image_fingerprint || $build->promoted_at === null)) {
            return;
        }
        $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('attempt', $operation->input['attempt'])
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
