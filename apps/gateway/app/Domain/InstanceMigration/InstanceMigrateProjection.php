<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The run input and the end of a move (docs/cli/instance/migrate.mmd). A move whose Route serves the container makes the container
 * the Instance's runtime: its checkout path becomes the workspace, the runtime runs, and a non-default Instance's T3 server is
 * recorded. A move that failed and restored drops the reserved runtime row, so the Instance is the checkout Instance it was.
 */
final readonly class InstanceMigrateProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    /** The one settlement rule, called by OperationStore in the first terminal transition's transaction. */
    public function terminal(Operation $operation, Operation $before): void
    {
        $instance = Instance::query()->lockForUpdate()->find($operation->target_id);
        $unstarted = ! isset($before->state['swamp_run_id']) && $before->attempts === 0
            && ! ($before->status === OperationStatus::Running && isset($before->state['swamp_start']));
        $refused = ! isset($operation->state['swamp_run_id']) && ($operation->state['native_start_not_submitted'] ?? false) === true;
        if ($instance !== null && ($unstarted || $refused) && ($operation->input['had_runtime_reservation'] ?? false) !== true) {
            $instance->runtime()->where('operation_id', $operation->id)->delete();
        }
        if (($unstarted || $refused) && is_array($operation->state['native_timeout'] ?? null)) {
            app(OperationStore::class)->change($operation, ['state' => [...$operation->state,
                'native_timeout' => [...ValidatedData::object($operation->state['native_timeout']), 'no_run_verified_at' => now()->toISOString()]]]);
        }
        app(DeferredMigrationScheduleRuns::class)->release($operation, targetAvailable: $instance !== null);
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $instance = Instance::query()->findOrFail($operation->target_id);
        $appSource = $instance->project->source_access === ProjectSourceAccess::GitHubApp;
        $source = $appSource ? app(InstanceGitHubReadTokens::class)->credentialCopy($instance->project->repository_url,
            'instance-migrate-source-'.$operation->id) : null;
        $repository = GitHubRepository::fromOrigin($instance->project->repository_url);
        $container = ValidatedData::object($input['container']);
        $publication = ($input['route_publication'] ?? null) === null ? null : ValidatedData::object($input['route_publication']);
        // Swamp evaluates the agent step's inputs for a default too, so every run has the copy; only an Instance with T3 holds a key.
        $model = ($container['clone'] ?? null) === null ? "# The default Instance runs no agent.\n"
            : app(InstanceContainer::class)->modelEnvironment($instance->runtime()->firstOrFail(), app(TaskVmModelRoute::class)->key());

        $plans = ValidatedData::object($input['migrate'])['schedules'] ?? [];
        if (! is_array($plans) || ! array_is_list($plans)) {
            throw new \InvalidArgumentException('The admitted Schedules must be a list.');
        }

        return ['intent' => [
            'instance_id' => $instance->id, 'instance_name' => $instance->name, 'ssh_execution' => $input['ssh_execution'],
            'container' => $container, 'migrate' => [...ValidatedData::object($input['migrate']),
                ...($source === null ? [] : ['source' => [
                    'repository' => $instance->project->repository_url,
                    'branch' => ValidatedData::string($instance->project->default_branch),
                    'access_denied_message' => $repository === null ? null : InstanceGitHubReadTokens::accessDeniedMessage(
                        app(GitHubAppStore::class)->credentials()->slug ?? '(not configured)', $repository),
                ]])],
            'route_mutations' => $publication === null ? null : ValidatedData::object($publication['route_mutations']),
        ], 'execution_secrets' => [
            ...($source === null ? [] : ['source_credential' => $source]),
            ...($publication === null ? [] : ValidatedData::object($publication['execution_secrets'])),
            'process_environments' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-migrate-processes-'.$operation->id,
                json_encode([...ValidatedData::object(json_decode(app(ContainerProcesses::class)->environments($instance, $container['processes'] ?? [], migrate: true), true, flags: JSON_THROW_ON_ERROR)),
                    'schedule_commands' => json_decode(app(MigrateSchedules::class)->commands($instance, array_map(ValidatedData::object(...), $plans)), true, flags: JSON_THROW_ON_ERROR)],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'model_environment' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'instance-migrate-model-'.$operation->id, $model),
        ]];
    }

    /** Keep the existing full hour from the recorded expiry; no failed read renews it. */
    public static function settlementLimit(Operation $operation): ?CarbonImmutable
    {
        $heldUntil = $operation->state['migrate_settlement_hold_limit'] ?? null;
        if (is_string($heldUntil)) {
            return CarbonImmutable::parse($heldUntil);
        }
        $expiry = $operation->error_code === 'operation.timed_out' && $operation->finished_at !== null
            ? CarbonImmutable::instance($operation->finished_at) : OperationRunBudgets::deadline($operation);

        return $expiry?->addMinutes(ExpireOperationAction::HoldMinutes);
    }

    /** Remember the first expiry's hold limit before any late-result read can reopen it. */
    public function rememberSettlementLimit(Operation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (self::unsettled($current) && ! isset($current->state['migrate_settlement_hold_limit'])) {
                app(OperationStore::class)->change($current, ['state' => [...($current->state ?? []),
                    'migrate_settlement_hold_limit' => self::settlementLimit($current)?->toISOString()]]);
            }
        });
    }

    /** One sweep interval lets a terminal receipt arrive after the native end. */
    private const int OutputGraceSeconds = 60;

    /**
     * Only the job calls this with an identity-verified terminal history. No Node effect runs here.
     *
     * @param  array<string,mixed>  $run
     */
    public function missingOutput(Operation $operation, array $run): void
    {
        $completed = CarbonImmutable::parse(ValidatedData::string($run['completedAt']));
        $failure = app(NativeStepFailure::class)->fromRun($run, $operation);
        DB::transaction(function () use ($operation, $run, $completed, $failure): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $this->direct->assertRun($current, ValidatedData::string($run['id']));
            if ($current->status->terminal() && ! self::unsettled($current)) {
                return;
            }
            $deadline = OperationRunBudgets::deadline($current);
            $atDeadline = $deadline !== null && ! $deadline->isFuture();
            if (! $atDeadline && $completed->addSeconds(self::OutputGraceSeconds)->isFuture()) {
                return;
            }
            $missing = $this->rollbackEvidence($run);
            if ($missing !== [] && ! $atDeadline) {
                return;
            }
            $instance = Instance::query()->lockForUpdate()->find($current->target_id);
            $owned = $instance?->runtime()->where('operation_id', $current->id)->exists() ?? false;
            $restored = $missing === [];
            $result = self::unavailableResult($current, $restored && $owned, $missing);
            // apply() holds this same Operation lock through the result and runtime writes.
            $this->apply($current, [
                'run_id' => $run['id'], 'status' => 'failed', 'started_at' => $run['startedAt'],
                'finished_at' => $run['completedAt'], 'native_run' => $run,
                'result' => [...$result, 'ready' => false, 'moved' => $restored ? false : null, 'restored' => $restored ? true : null],
                'failure' => $failure,
            ]);
        });
    }

    /**
     * A successful restore can be a preflight no-op. Require completed preparation and no retirement too.
     *
     * @param  array<string,mixed>  $run
     * @return list<string>
     */
    private function rollbackEvidence(array $run): array
    {
        $steps = [];
        foreach (is_array($run['jobs'] ?? null) ? $run['jobs'] : [] as $job) {
            if (! is_array($job) || ($job['name'] ?? null) !== 'migrate') {
                continue;
            }
            foreach (is_array($job['steps'] ?? null) ? $job['steps'] : [] as $step) {
                if (! is_array($step) || ! is_string($step['name'] ?? null) || isset($steps[$step['name']])) {
                    return ['unambiguous_migrate_steps'];
                }
                $steps[$step['name']] = $step;
            }
        }
        $missing = [];
        if (($steps['container']['status'] ?? null) !== 'succeeded') {
            $missing[] = 'container_preparation';
        }
        if (($steps['retire']['status'] ?? null) !== 'skipped' || isset($steps['retire']['startedAt'])) {
            $missing[] = 'retirement_not_started';
        }
        if (($steps['restore']['status'] ?? null) !== 'succeeded') {
            $missing[] = 'checkout_restored';
        }
        $routeUntouched = ($steps['route']['status'] ?? null) === 'skipped' && ! isset($steps['route']['startedAt']);
        $routeOutput = $steps['restore-route']['outputs'] ?? null;
        $routeResult = is_array($routeOutput) ? ($routeOutput['result'] ?? null) : null;
        $routeRestored = ($steps['restore-route']['status'] ?? null) === 'succeeded'
            && is_array($routeResult) && ($routeResult['ready'] ?? null) === true;
        if (! $routeUntouched && ! $routeRestored) {
            $missing[] = 'route_restored';
        }

        return $missing;
    }

    /** @param list<string> $missing
     * @return array<string,mixed>
     */
    private static function unavailableResult(Operation $operation, bool $released, array $missing): array
    {
        $evidence = implode(', ', ['finish_output', ...$missing]);
        $repair = $released ? 'The run proved rollback. Its owned runtime reservation is released. Retry instance:migrate.'
            : 'The runtime reservation stays. The owner must recover the finish receipt or verify and reconcile rollback or cutover. No Orbit command repairs this evidence gap.';

        return ['instance_id' => $operation->target_id, 'error_code' => 'instance.migrate_result_unavailable',
            'error_message' => "Operation [{$operation->id}] is missing {$evidence}. {$repair}",
            'missing_evidence' => ['finish_output', ...$missing],
            'recovery' => ['operation_id' => $operation->id, 'runtime_reservation' => $released ? 'released' : 'retained',
                'repair_command' => $released ? 'instance:migrate '.$operation->target_id : null]];
    }

    /** The reconciler ends result recovery at the existing hold limit without claiming physical restoration. */
    public function endUnavailable(Operation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! self::unsettled($current) || (self::settlementLimit($current)?->isFuture() ?? true)) {
                return;
            }
            app(OperationStore::class)->change($current, [
                'error_code' => 'instance.migrate_result_unavailable', 'lock_key' => null,
                'result' => [...($current->result ?? []), ...self::unavailableResult($current, false, ['native_end', 'checkout_restored', 'route_restored'])],
                'state' => [...($current->state ?? []),
                    'native_failure' => [...ValidatedData::object($current->state['native_failure'] ?? []),
                        'detail' => 'The reconciler could not read the migrate result before its target hold limit. The container and address reservation remain.',
                        'stderr_recorded' => false],
                    'native_timeout' => [
                        ...ValidatedData::object($current->state['native_timeout'] ?? []),
                        'native_terminal_status' => 'unproven', 'result_unavailable_at' => now()->toISOString(),
                    ]],
            ]);
        });
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $container = ValidatedData::object($operation->input['container'] ?? []);
        $result = ValidatedData::object($data['result'] ?? []);
        $run = ValidatedData::object($data['native_run'] ?? []);
        if ($run !== []) {
            $data['step_outcomes'] = $this->steps($run);
        }
        if ($data['status'] === 'interrupted' && ! array_key_exists('moved', $result)) {
            $steps = array_map(ValidatedData::object(...), is_array($data['step_outcomes'] ?? null) ? $data['step_outcomes'] : []);
            $began = static fn (string $name): bool => array_any($steps, static fn (array $step): bool => $step['job'] === 'migrate'
                && $step['name'] === $name && ($step['started_at'] !== null || ! in_array($step['status'], ['skipped', 'pending'], true)));
            $unsafe = $began('retire');
            $result = [...$result, 'ready' => false, 'moved' => null, 'restored' => null, 'native_finish_missing' => true,
                'quiesced' => $began('quiesce'), 'retirement_started' => $unsafe];
        }
        // From the removal of the checkout on, the container is the Instance, whatever the removal itself did.
        $moved = ($result['moved'] ?? false) === true;
        // The T3 session is read from the vault before the transaction, so no lock waits on Swamp.
        $server = $moved && ($container['clone'] ?? null) !== null
            ? app(DevelopmentInstanceCreateProjection::class)->server(ValidatedData::object(ValidatedData::object($container['clone'])['t3']), $result) : null;

        return DB::transaction(function () use ($operation, $data, $result, $moved, $server, $run): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal() && ! self::unsettled($operation)) {
                return $operation;
            }
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $nativeStatus = $run['status'] ?? $data['status'];
            $timeout = ValidatedData::object($operation->state['native_timeout'] ?? []);
            $deadline = OperationRunBudgets::deadline($operation);
            if ($timeout === [] && $deadline !== null && Carbon::parse(ValidatedData::string($data['finished_at']))->greaterThan($deadline)) {
                $operation->update(['state' => [...($operation->state ?? []), 'native_timeout' => [
                    'budget_seconds' => OperationRunBudgets::seconds($operation), 'deadline' => $deadline->toISOString(),
                    'cancel_requested_before_deadline' => $operation->cancel_requested_at !== null,
                    'native_terminal_status' => $nativeStatus, 'native_terminal_observed_at' => now()->toISOString(),
                ]]]);
            }
            // Recheck cancellation cause from the durable audit after all private reads, under this same lock.
            if ($data['status'] === 'cancelled') {
                $code = $result['error_code'] ?? null;
                if (is_string($code) && ! $this->cancelledFailure(ValidatedData::object($data['native_run'] ?? []))) {
                    $data['status'] = 'failed';
                    $data['error_code'] = $code;
                } elseif (self::deadlineCancellation($operation)) {
                    $data['status'] = 'failed';
                    $data['error_code'] = 'operation.timed_out';
                    $result['error_code'] = 'operation.timed_out';
                } else {
                    $data['error_code'] = null;
                }
            } elseif (in_array($data['status'], ['failed', 'interrupted'], true)) {
                if ($result === [] || (($result['outcome'] ?? null) === 'succeeded' && ! is_string($result['error_code'] ?? null))) {
                    $result = ['error_code' => 'operation.native_failed'];
                }
                $data['error_code'] = $result['error_code'] ?? 'operation.native_failed';
            } else {
                $data['error_code'] = null;
            }
            if (self::unsettled($operation)) {
                $state = $operation->state ?? [];
                $restore = ValidatedData::object($state['native_timeout_restore'] ?? []);
                $timeout = ValidatedData::object($state['native_timeout'] ?? []);
                unset($state['native_timeout_restore']);
                if (($restore['native_failure'] ?? true) !== true) {
                    unset($state['native_failure']);
                }
                $state['native_timeout'] = [...$timeout,
                    'cancel_requested_before_deadline' => ($restore['cancel_requested'] ?? false) === true,
                    'native_terminal_status' => $nativeStatus, 'native_terminal_observed_at' => now()->toISOString()];
                app(OperationStore::class)->change($operation, ['status' => OperationStatus::Running, 'finished_at' => null,
                    'error_code' => null, 'failed_step' => null, 'state' => $state,
                    'cancel_requested_at' => ($restore['cancel_requested'] ?? true) ? $operation->cancel_requested_at : null]);
            }
            $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
            if (($data['status'] === 'succeeded' && (($result['ready'] ?? false) !== true
                    || ! $moved))
                || ($moved && (($result['instance_id'] ?? null) !== $instance->id || ! is_string($result['commit'] ?? null)))) {
                throw new ResourceOperationException('instance.result_invalid', 'The move did not prove its complete terminal result.');
            }
            if ($moved && (($result['ready'] ?? false) !== true && ($result['failed_step'] ?? null) !== 'retire')) {
                throw new ResourceOperationException('instance.result_invalid', 'The move did not prove Process readiness before cutover.');
            }
            $runtime = $instance->runtime()->lockForUpdate()->first();
            if (! $operation->status->terminal() && $runtime?->operation_id === $operation->id) {
                if ($moved && is_string($result['retained_git_path'] ?? null) && is_string($result['retained_git_at'] ?? null)) {
                    $instance->update(['retained_git_path' => $result['retained_git_path'], 'retained_git_at' => $result['retained_git_at']]);
                }
                if ($moved) {
                    $units = ValidatedData::object($operation->input['container'])['processes'] ?? [];
                    app(MigratedProcessDefinitions::class)->capture($instance,
                        ValidatedData::integerList(array_column(is_array($units) ? $units : [], 'id')));
                    app(ContainerProcesses::class)->complete($instance, ValidatedData::object($operation->input['container'])['processes'] ?? [], true, null, migrate: true);
                    $schedules = ValidatedData::object($operation->input['migrate'])['schedules'] ?? [];
                    if (! is_array($schedules) || ! array_is_list($schedules)) {
                        throw new \InvalidArgumentException('The admitted Schedules must be a list.');
                    }
                    app(MigrateSchedules::class)->complete($instance, array_map(ValidatedData::object(...), $schedules), $operation->id);
                    $this->moved($instance, ValidatedData::object($operation->input['migrate']), ValidatedData::integerList($operation->input['sqlite_connection_ids'] ?? []));
                    // A default's clones start at the seed snapshot the run took of its commit.
                    $runtime->update(['power_state' => InstanceRuntimePowerState::Running,
                        ...($server === null ? ['seed_commit' => ValidatedData::string($result['commit'])] : [])]);
                    if ($data['status'] === 'succeeded') {
                        // This run published the container's Route inline; no later route.update receipt is needed.
                        $instance->update(['development_projection_pending' => false]);
                    }
                    if ($server !== null) {
                        app(InstanceLifecycleContributions::class)->projectCreate($instance, $server);
                    }
                } elseif (($result['restored'] ?? false) === true
                    && ! (($result['preflight_refused'] ?? false) === true && ($operation->input['had_runtime_reservation'] ?? false) === true)) {
                    // The run removed its container, so the address is free again. A restore that failed keeps the row and its address.
                    $runtime->delete();
                }
            }

            if ($run !== []) {
                $progress = ValidatedData::object($operation->state['native_progress'] ?? []);
                $operation->update(['state' => [...($operation->state ?? []), 'native_progress' => [
                    'run' => $data['run_id'], 'seq' => $progress['seq'] ?? 0,
                    'steps' => $this->steps($run), 'active' => [],
                ]]]);
            }

            return $this->direct->project($operation, $data, $result);
        });
    }

    /** Only migrate retains native result recovery after its target hold has ended. */
    public static function unsettled(Operation $operation): bool
    {
        $timeout = $operation->state['native_timeout'] ?? null;

        return $operation->kind === 'instance.migrate' && $operation->status === OperationStatus::Failed
            && $operation->error_code === 'operation.timed_out' && is_array($timeout)
            && ! isset($timeout['no_run_verified_at'])
            && (! isset($timeout['native_terminal_status']) || $timeout['native_terminal_status'] === 'unproven');
    }

    private static function deadlineCancellation(Operation $operation): bool
    {
        $timeout = $operation->state['native_timeout'] ?? null;
        $restore = ValidatedData::object($operation->state['native_timeout_restore'] ?? []);

        return is_array($timeout) && ($timeout['cancel_requested_before_deadline'] ?? $restore['cancel_requested'] ?? false) !== true;
    }

    /** Native steps describe only this migrate receipt, including when its progress events were lost.
     * @param  array<string,mixed>  $run
     * @return list<array<string,mixed>>
     */
    private function steps(array $run): array
    {
        $steps = [];
        foreach (is_array($run['jobs'] ?? null) ? $run['jobs'] : [] as $job) {
            if (! is_array($job) || ! is_string($job['name'] ?? null)) {
                continue;
            }
            foreach (is_array($job['steps'] ?? null) ? $job['steps'] : [] as $step) {
                if (is_array($step) && is_string($step['name'] ?? null)) {
                    $steps[] = ['job' => $job['name'], 'name' => $step['name'], 'status' => $step['status'] ?? 'unknown',
                        'started_at' => $step['startedAt'] ?? null, 'finished_at' => $step['completedAt'] ?? null];
                }
            }
        }

        return $steps;
    }

    /** The first native failure distinguishes an aborted phase from an independent failure before cancellation.
     * The pinned command/shell records a process killed by cancellation as failed, with native exit code -1.
     *
     * @param  array<string,mixed>  $run
     */
    private function cancelledFailure(array $run): bool
    {
        $first = $this->firstFailure($run);
        $outputs = is_array($first['outputs'] ?? null) ? $first['outputs'] : [];

        return in_array($first['status'] ?? null, ['cancelled', 'aborted'], true)
            || ($first['error'] ?? null) === 'Command exited with code -1'
            || ($outputs['exitCode'] ?? null) === -1;
    }

    /** @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function firstFailure(array $run): array
    {
        $failures = [];
        foreach (is_array($run['jobs'] ?? null) ? $run['jobs'] : [] as $job) {
            foreach (is_array($job) && is_array($job['steps'] ?? null) ? $job['steps'] : [] as $step) {
                if (is_array($step) && ($step['allowedFailure'] ?? false) !== true && in_array($step['status'] ?? null, ['failed', 'cancelled', 'aborted'], true)) {
                    $failures[] = $step;
                }
            }
        }
        usort($failures, static fn (array $left, array $right): int => strcmp(
            is_string($left['completedAt'] ?? null) ? $left['completedAt'] : '',
            is_string($right['completedAt'] ?? null) ? $right['completedAt'] : ''));

        return $failures[0] ?? [];
    }

    /**
     * The Instance's records after the move. The workspace is its checkout from now on; `.env` came as it was, with the checkout's
     * path rewritten, so the stored values follow. A SQLite file moved with the files and is part of the workspace now, as a
     * container Instance's is: its connection record goes.
     *
     * @param  array<string, mixed>  $migrate
     * @param  list<int>  $sqlite
     */
    private function moved(Instance $instance, array $migrate, array $sqlite): void
    {
        $checkout = rtrim(ValidatedData::string($migrate['checkout']), '/');
        $store = app(InstanceEnvironmentStore::class);
        $rewritten = [];
        foreach ($store->values($instance) as $key => $value) {
            if (str_contains($value, $checkout)) {
                $rewritten[$key] = str_replace($checkout, InstanceHostIntent::Workspace, $value);
            }
        }
        if ($rewritten !== []) {
            $store->seedForCreate($instance, $rewritten);
        }
        DatabaseConnectionTarget::query()->where('instance_id', $instance->id)->whereIn('database_connection_id', $sqlite)->delete();
        DatabaseConnection::query()->whereIn('id', $sqlite)->where('owner_instance_id', $instance->id)
            ->whereNotIn('id', DatabaseConnectionTarget::query()->select('database_connection_id'))->delete();
        $instance->update(['checkout_path' => InstanceContainer::workspaceOnNode($instance), 'source_layout' => InstanceSourceLayout::Checkout,
            'registration_common_repository_path' => null, 'seed_repository' => null, 'seed_commit' => null, 'seed_path' => null,
            'failed_step' => null, 'error_code' => null]);
    }
}
