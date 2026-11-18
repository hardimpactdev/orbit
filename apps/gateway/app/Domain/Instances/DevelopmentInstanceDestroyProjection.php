<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Domain\DatabaseServers\DatabaseDestroyProjection;
use App\Domain\DatabaseServers\DatabaseOwnedRemoval;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\Power\ExecutorFence;
use App\Domain\Instances\Power\ExecutorPowerIntent;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Tasks\TaskCopyLifecycle;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Frozen public removal DAG input and terminal record projection only. */
final readonly class DevelopmentInstanceDestroyProjection implements DirectOperationProjection, DirectOperationStartRefusal
{
    /** The refusals a container phase names itself (swamp/ansible/instance-container-destroy.yml); any other code stays generic. */
    public const array PhaseCodes = ['instance.removal_dataset_taken'];

    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $sourceCapture = InstanceRemoval::query()->findOrFail(ValidatedData::string($input['removal_id']))->native_sources;
        $native = ValidatedData::object($input['native_destroy']);
        $secrets = ValidatedData::object($native['execution_secrets']);
        unset($native['execution_secrets']);
        $detail = ValidatedData::object($input['direct_destroy']);
        $database = ValidatedData::object($detail['database']);
        // Owned server databases drop through one database:destroy child per server, with that server's admin login.
        [$database['servers'], $serverCopies] = DatabaseOwnedRemoval::servers(ValidatedData::integer($database['intent_id']),
            $database['servers'] ?? [], ! $operation->status->terminal());
        $detail['database'] = $database;
        $detail['hibernation'] = self::hibernationFiles($input);
        $public = [...Arr::only($input, ['container_destroy', 'removal_id', 'node_id', 'instance_id', 'project_id', 'name', 'force', 'inventory_digest', 'repository', 'source_pending', 'protected_source_roots', 'members', 'route_withdrawn']), 'direct_destroy' => $detail];
        $programs = ValidatedData::object(app(InstanceDestroyTeardownSecret::class)->resolve($operation, $operation->target_id, '', 'teardown')->value);
        $teardown = [];
        foreach (InstanceLifecycleInput::members($programs) as $member) {
            $instance = Instance::query()->findOrFail(ValidatedData::integer($member['instance_id']));
            foreach (InstanceLifecycleInput::members(['members' => $member['steps']]) as $step) {
                $command = ValidatedData::string($step['command']);
                $teardown[] = ['name' => $step['name'], 'command' => $command, 'sha256' => hash('sha256', $command), 'timeout_seconds' => $step['timeout_seconds'],
                    'directory' => $instance->checkout_path, 'instance_id' => $instance->id, 'instance_name' => $instance->name, 'seed_path' => '', 'seed_commit' => ''];
            }
        }
        $members = InstanceLifecycleInput::members($input);
        $fetches = $input['force'] !== true && array_filter($members, static fn (array $member): bool => ($member['failed_creation'] ?? false) !== true) !== [];
        // A normal removal checks each checkout's work is pushed with a fresh read token, read as a 0600 credential file.
        $git = [];
        if ($fetches) {
            app(InstanceGitHubReadTokens::class)->start($operation);
            $git = ['git_credential' => InstanceGitHubReadTokens::credentialReference($operation)];
        }
        $checked = $input['force'] === true ? [] : array_values(array_filter($members, static fn (array $member): bool => ($member['failed_creation'] ?? false) !== true));

        return ['intent' => [...$public, ...$native, 'source_capture' => $sourceCapture, 'teardown_steps' => $teardown,
            'push_check_paths' => array_map(static fn (array $member): string => ValidatedData::string($member['checkout_path']), $checked),
            'source_paths' => array_map(static fn (array $member): string => ValidatedData::string($member['checkout_path']), $members)],
            'execution_secrets' => [...$secrets, ...$serverCopies, ...$git]];
    }

    /**
     * Each member's hibernation files, so a removed Instance leaves no awake or cold marker or access log behind.
     * Rendered at run start, so a removal recorded earlier gets them too.
     *
     * @param  array<string,mixed>  $input
     * @return list<array{instance_id:int,path:string}>
     */
    public static function hibernationFiles(array $input): array
    {
        $files = [];
        foreach (InstanceLifecycleInput::members($input) as $member) {
            $key = RuntimeHibernation::key(ValidatedData::integer($member['instance_id']));
            foreach ([RuntimeHibernation::awakePath($key), RuntimeHibernation::coldPath($key), RuntimeHibernation::accessLogPath($key)] as $path) {
                $files[] = ['instance_id' => ValidatedData::integer($member['instance_id']), 'path' => $path];
            }
        }

        return $files;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
        if (($operation->input['container_destroy'] ?? null) !== null && $data['status'] === 'succeeded' && ! $operation->status->terminal()) {
            app(ContainerDestroyExecutionCopies::class)->remove($operation);
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->lockForUpdate()->findOrFail($operation->id)->getAttributes(), true);
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $unproven = $this->recordRemovalReceipt($operation, $data);
            if ($unproven) {
                $data = [...$data, 'status' => 'interrupted'];
            }
            [$code, $result] = $this->outcome($data, $operation);
            if ($unproven) {
                [$code, $result] = ['executor.result_unproven', [...$result, 'error_code' => 'executor.result_unproven']];
            }
            if ($data['status'] === 'succeeded') {
                $result = [...$result, ...Arr::only($operation->input, ['retained_git_path', 'retained_git_at'])];
            }
            $data = [...$data, 'error_code' => $code, 'failure' => $this->failure($data, $result, $operation)];
            if (! $operation->status->terminal()) {
                $run = ValidatedData::object($data['result'] ?? []);
                if (in_array($data['status'], ['failed', 'interrupted'], true)
                    && NativeRunResult::stepStatus($run, 'Stop the container') === 'succeeded') {
                    $operation->update(['state' => [...($operation->state ?? []), 'container_stop_succeeded' => true]]);
                }
                if ($data['status'] === 'succeeded') {
                    // A task's copy lets go of its group before its row goes; any other Instance has no owner and nothing happens.
                    app(TaskCopyLifecycle::class)->destroyed($operation->target_id);
                    app(ProjectDirectInstanceDestroyAction::class)->execute(app(InstanceDestroySourceInspection::class)->executable($operation->input), $result);
                } elseif (in_array($data['status'], ['failed', 'cancelled'], true)) {
                    InstanceRemoval::query()->whereKey($operation->input['removal_id'])->update(['status' => InstanceRemovalStatus::Failed,
                        'error_code' => $data['error_code'] ?? 'operation.cancelled', 'current_step' => $this->failedStep($result), 'failed_step' => $this->failedStep($result)]);

                }
            }

            return $this->direct->project($operation, $data, $result);
        });
    }

    /**
     * ADR 0266, R1 and R2: a signed removal's stop phase reports the executor's discard receipt in the removal file, which "Read container
     * removal" prints; it is recorded once on the Operation. Returns whether a run that reported success issued its order and has no
     * receipt: that is never a success, and the Operation keeps its fence.
     *
     * @param  array<string,mixed>  $data
     */
    private function recordRemovalReceipt(Operation $operation, array $data): bool
    {
        if (ExecutorPowerIntent::action($operation, 'removal') === null) {
            return false;
        }
        $run = is_array($data['result'] ?? null) ? $data['result'] : [];
        $read = is_array($run['steps'] ?? null) && is_array($run['steps']['Read container removal'] ?? null) ? $run['steps']['Read container removal'] : [];
        $file = is_string($read['stdout'] ?? null) ? json_decode(trim($read['stdout']), true) : null;
        ExecutorFence::record($operation, 'discard', is_array($file) ? ($file['executor'] ?? null) : null);

        return ($data['status'] ?? null) === 'succeeded' && ExecutorFence::holds($operation);
    }

    /**
     * A destroy whose run never started leaves its removal failed, as a failed run would: at its first step, or at the step
     * an earlier failed run of the same removal stopped at. The Instance keeps
     * status `removing`, and a new destroy of it (also with `--force`) is admitted. Runs inside the refusal's transaction.
     */
    public function refused(Operation $operation): void
    {
        $id = $operation->input['removal_id'] ?? null;
        if (! is_string($id)) {
            return;
        }
        $removal = InstanceRemoval::query()->whereKey($id)->where('status', InstanceRemovalStatus::Removing)->first();
        // A retry of a removal that failed mid-run keeps the step where that run stopped; admission clears only failed_step.
        if ($removal === null) {
            return;
        }
        $step = $removal->current_step ?? InstanceRemovalStep::SourcePreparation;
        $removal->update(['status' => InstanceRemovalStatus::Failed, 'error_code' => $operation->error_code ?? 'swamp.start_unavailable',
            'current_step' => $step, 'failed_step' => $step]);
    }

    /** The public result from Swamp's run data: the first failed step names the phase; a forEach item's index suffix is dropped.
     * @param  array<string,mixed>  $data
     * @return array{0:?string,1:array<string,mixed>}
     */
    private function outcome(array $data, Operation $operation): array
    {
        $run = ValidatedData::object($data['result'] ?? []);
        $warnings = is_array($operation->input['checkout_warnings'] ?? null) ? $operation->input['checkout_warnings'] : [];
        $diagnostics = $warnings === [] ? [] : ['warnings' => $warnings];
        // Whether the run gave its Route publication hold back after the withdraw step, with the Gateway's answer.
        $diagnostics = [...$diagnostics, ...self::holdRelease($run['route_hold_release'] ?? null)];
        if (($data['status'] ?? null) === 'succeeded') {
            return [null, ['removed' => true, ...$diagnostics]];
        }
        if (! in_array($data['status'] ?? null, ['failed', 'interrupted'], true)) {
            return [ValidatedData::nullableString($data['error_code'] ?? null), $diagnostics];
        }
        $withdrawal = $run['withdrawal'] ?? null;
        if (is_array($withdrawal) && ($withdrawal['ready'] ?? null) === false) {
            return ['route.withdrawal_failed', ['removed' => false, 'failed_step' => 'Withdraw the Route', 'error_code' => 'route.withdrawal_failed', ...$diagnostics]];
        }
        $summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
        $first = is_array($summary['failed_steps'] ?? null) && is_string($summary['failed_steps'][0] ?? null) ? $summary['failed_steps'][0] : null;
        $label = $first === null ? null : preg_replace('/-[0-9]+\z/', '', $first);
        $steps = is_array($run['steps'] ?? null) ? $run['steps'] : [];
        $failed = $first !== null && is_array($steps[$first] ?? null) ? $steps[$first] : [];
        $exit = is_int($failed['exit_code'] ?? null) ? $failed['exit_code'] : null;
        $code = match ($label) {
            'Check work is pushed', 'Remove the source' => 'instance.remove_refused',
            'Run teardown steps' => match ($exit) {
                124 => 'command.deadline_exceeded',
                127 => 'instance.teardown_step_unavailable',
                default => 'instance.teardown_step_failed',
            },
            default => 'instance.removal_incomplete',
        };
        // The container phases name a refusal of their own in their result file; only a known one replaces the generic code.
        $phase = $this->phaseFile($run, $label);
        if (in_array($phase['error_code'] ?? null, self::PhaseCodes, true)) {
            $code = $phase['error_code'];
        }
        // A server child's steps are in this run's data. Its drop exits 1 on a blocker, which the child allows, so its end check is
        // the step that fails: the child's own outcome names the refusal, as it does for database:destroy.
        if (array_intersect(ValidatedData::stringList($summary['failed_steps'] ?? []), ['Fence new clients', 'Drop databases and users', 'Check they are gone']) !== []) {
            [$child, $refusal] = DatabaseDestroyProjection::outcome('', $data);
            $failure = $child['failure'] ?? null;
            if (is_string($refusal['error_code'] ?? null) && is_array($failure) && is_string($failure['failed_step'] ?? null)) {
                [$label, $code] = [$failure['failed_step'], $refusal['error_code']];
            }
        }

        return [$code, ['removed' => false, 'failed_step' => $label, 'error_code' => $code, ...$diagnostics]];
    }

    /**
     * The release step prints one JSON line; a run without the step (no Route, a withdraw that did not hold, an older run) has none.
     *
     * @return array{route_hold_released?:bool,route_hold_release_status?:int}
     */
    private static function holdRelease(mixed $stdout): array
    {
        $line = is_string($stdout) ? json_decode(trim($stdout), true) : null;
        if (! is_array($line) || ! is_bool($line['route_hold_released'] ?? null)) {
            return [];
        }
        $status = $line['http_status'] ?? null;

        return ['route_hold_released' => $line['route_hold_released'],
            'route_hold_release_status' => is_string($status) && ctype_digit($status) ? (int) $status : 0];
    }

    /**
     * A failed container stop or deletion writes its failed task and the end of its stderr into remove.json, because Swamp keeps no stderr of
     * a failed command step, and "Read container removal" prints the file. That line becomes the failure's recorded stderr.
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $result
     */
    private function failure(array $data, array $result, Operation $operation): mixed
    {
        $native = $data['failure'] ?? null;
        $file = $this->phaseFile(is_array($data['result'] ?? null) ? $data['result'] : [], $result['failed_step'] ?? null);
        if (! is_string($file['detail'] ?? null) || trim($file['detail']) === '') {
            return $native;
        }
        $step = is_array($native) && is_string($native['failed_step'] ?? null) ? $native['failed_step'] : ValidatedData::string($result['failed_step']);

        return app(NativeStepFailure::class)->written($step, $file['detail'], $operation);
    }

    /**
     * The result file of a failed container phase, which "Read container removal" prints: its refusal code and its failed task with
     * the end of its stderr. Empty for any other failed step.
     *
     * @param  array<mixed>  $run
     * @return array<mixed>
     */
    private function phaseFile(array $run, mixed $failedStep): array
    {
        $read = is_array($run['steps'] ?? null) && is_array($run['steps']['Read container removal'] ?? null) ? $run['steps']['Read container removal'] : [];
        $file = in_array($failedStep, ['Stop the container', 'Delete the container'], true) && is_string($read['stdout'] ?? null)
            ? json_decode(trim($read['stdout']), true) : null;

        return is_array($file) ? $file : [];
    }

    /** @param array<string,mixed> $result */
    private function failedStep(array $result): InstanceRemovalStep
    {
        $step = $result['failed_step'] ?? '';
        if (! is_string($step)) {
            return InstanceRemovalStep::SourcePreparation;
        }

        return match ($step) {
            'Remove the source', 'Check it is gone' => InstanceRemovalStep::SourceFinalization,
            'Withdraw the Route' => InstanceRemovalStep::RouteTargetClear,
            'Stop, remove Processes', 'Run teardown steps', 'Remove SQLite files', 'Stop the container', 'Drop owned databases',
            'Fence new clients', 'Drop databases and users', 'Check they are gone' => InstanceRemovalStep::RuntimeCleanup,
            default => InstanceRemovalStep::SourcePreparation,
        };
    }
}
