<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationResultFields;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Schedules\ScheduleFires;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/** Shared command-step boundary: names into native vault env, public JSON out of stdout. */
final readonly class SwampCommandStep
{
    public function __construct(private SwampVaultExecutionCopy $copies, private WebSocketClient $socket) {}

    /** Bind this descriptor with a whole task.inputs.env vault.get expression, never run/argv.
     * @return array{vault:string,key:string}
     */
    public function executionCopy(string $vault, string $key, #[SensitiveParameter] string $value): array
    {
        return $this->copies->synchronize($vault, $key, $value);
    }

    /** One native lookup for a lost start confirmation. Missing evidence never permits resubmission. */
    public function recoverUnbound(Operation $operation): void
    {
        $direct = app(DirectOperation::class);
        $workflow = $direct->definition($operation->kind)['workflow'] ?? null;
        $start = ['key' => 'operation:'.$operation->id, 'workflow' => $workflow];
        $timedOut = ExpireOperationAction::awaitsSettlement($operation);
        if ($workflow === null || ! $direct->usesNativeTerminal($operation) || isset($operation->state['swamp_run_id'])
            || (isset($operation->state['swamp_start']) && $operation->state['swamp_start'] !== $start)
            || (! $timedOut && ($operation->status !== OperationStatus::Running || $operation->error_code !== 'swamp.start_uncertain'
                || ($operation->state['swamp_start'] ?? null) !== $start))) {
            return;
        }
        $reply = $this->read('workflow.run.search', [...(! $timedOut ? ['workflow' => $workflow] : []),
            'inputs' => ['operation_id' => (string) $operation->id, 'node_id' => (string) $operation->node_id, 'kind' => $operation->kind],
            'tags' => ['orbit_start_key' => $start['key']], 'limit' => 2]);
        $data = $reply['data'] ?? null;
        $rows = is_array($data) ? ($data['results'] ?? null) : null;
        if ($timedOut && ($reply['total'] ?? null) === 0 && $rows === []) {
            DB::transaction(function () use ($operation): void {
                $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
                if (! ExpireOperationAction::awaitsSettlement($current) || isset($current->state['swamp_run_id'])
                    || $current->kind !== $operation->kind || $current->node_id !== $operation->node_id
                    || ($current->state['swamp_start'] ?? null) !== ($operation->state['swamp_start'] ?? null)) {
                    return;
                }
                $timeout = $current->state['native_timeout'] ?? [];
                app(OperationStore::class)->change($current, ['lock_key' => null,
                    'state' => [...($current->state ?? []), 'native_timeout' => [
                        ...(is_array($timeout) ? $timeout : []), 'no_run_verified_at' => now()->toISOString(),
                    ]],
                ]);
            });

            return;
        }
        if (($reply['total'] ?? null) !== 1 || ! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0])) {
            throw $this->unavailable();
        }
        $run = $rows[0];
        $runId = $run['runId'] ?? null;
        $inputs = $run['inputs'] ?? null;
        $tags = $run['tags'] ?? null;
        if (! is_string($runId) || ! Str::isUuid($runId) || isset($run['parentRun'])
            || ($run['workflowName'] ?? null) !== $workflow || ! is_array($inputs) || ! is_array($tags)
            || ($inputs['operation_id'] ?? null) !== $operation->id
            || ($inputs['node_id'] ?? null) !== $operation->node_id || ($inputs['kind'] ?? null) !== $operation->kind
            || ($tags['orbit_start_key'] ?? null) !== $start['key']) {
            throw $this->unavailable();
        }
        DB::transaction(function () use ($operation, $runId, $start): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (isset($current->state['swamp_run_id'])
                || (! ExpireOperationAction::awaitsSettlement($current) && ($current->status !== OperationStatus::Running
                    || $current->error_code !== 'swamp.start_uncertain' || ($current->state['swamp_start'] ?? null) !== $start))
                || $current->node_id !== $operation->node_id || $current->kind !== $operation->kind) {
                return;
            }
            app(OperationStore::class)->change($current, ['dispatched_at' => now(), 'error_code' => ExpireOperationAction::awaitsSettlement($current) ? $current->error_code : null,
                'state' => [...($current->state ?? []), 'swamp_run_id' => $runId]]);
        });
    }

    /** Called by the shared native terminal subscriber, never by a family step.
     * @param  array<string,mixed>  $terminalRun  Native event.run, or its bound id after a late attach miss.
     * @param  array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>,native?:true,data?:string}  $selector  The fixed family terminal_result declaration.
     */
    public function projectTerminal(Operation $operation, array $terminalRun, array $selector, bool $atDeadline = false): Operation
    {
        $direct = app(DirectOperation::class);
        $runId = $terminalRun['id'] ?? null;
        $hasStatus = array_key_exists('status', $terminalRun);
        $terminalStatuses = ['succeeded', 'failed', 'cancelled', 'interrupted'];
        if (! is_string($runId) || ($hasStatus && ! in_array($terminalRun['status'], $terminalStatuses, true))) {
            throw $this->unavailable();
        }
        $direct->assertRun($operation, $runId);
        if (! $direct->usesNativeTerminal($operation)) {
            return $operation;
        }
        $operation->refresh();
        if ($operation->status->terminal() && ! ExpireOperationAction::expiredUnsettled($operation)) {
            return $operation;
        }
        $run = $this->history($runId);
        $inputs = $run['inputs'] ?? null;
        $workflow = $direct->definition($operation->kind)['workflow'] ?? throw $this->unavailable();
        if (($run['id'] ?? null) !== $runId || ($run['workflowName'] ?? null) !== $workflow
            || ! in_array($run['status'] ?? null, [...$terminalStatuses, 'pending', 'running', 'suspended'], true)
            || ($hasStatus && $run['status'] !== $terminalRun['status']) || ! is_array($inputs)
            || ($inputs['operation_id'] ?? null) !== $operation->id || ($inputs['node_id'] ?? null) !== $operation->node_id
            || ($inputs['kind'] ?? null) !== $operation->kind) {
            throw $this->unavailable();
        }
        if (! in_array($run['status'], $terminalStatuses, true)) {
            $deadline = OperationRunBudgets::deadline($operation);
            if (! $atDeadline || $deadline === null || $deadline->isFuture()) {
                // The notice that the run ended can come before serve writes its history; the job reads again.
                throw new SwampTerminalReadUnavailable;
            }

            return app(ExpireOperationAction::class)->execute($operation);
        }
        $deadline = OperationRunBudgets::deadline($operation);
        try {
            $completedAt = is_string($run['completedAt'] ?? null) ? Carbon::parse($run['completedAt']) : null;
        } catch (Throwable) {
            throw $this->unavailable();
        }
        if ($deadline !== null && ! $deadline->isFuture() && ($completedAt === null || $completedAt->greaterThan($deadline))) {
            $operation = app(ExpireOperationAction::class)->execute($operation);
        }
        // An expiry the run beat, typically at the admission deadline before a slow start reply bound the run.
        if (ExpireOperationAction::expiredUnsettled($operation) && $completedAt !== null && $deadline !== null && $completedAt->lessThan($deadline)) {
            $operation = app(ExpireOperationAction::class)->reopen($operation, $runId);
        }
        if (ExpireOperationAction::awaitsSettlement($operation)) {
            return app(ExpireOperationAction::class)->settle($operation, $runId, $run['status']);
        }
        $result = $this->terminalOutput($run, $selector);
        $status = $run['status'];
        if (in_array($status, ['failed', 'interrupted'], true) && ($result === [] || ($result['outcome'] ?? null) === 'succeeded')) {
            $result = ['error_code' => 'operation.native_failed'];
        }

        $current = DB::transaction(function () use ($direct, $operation, $runId): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $direct->assertRun($current, $runId);

            return $current;
        });
        if (ExpireOperationAction::awaitsSettlement($current)) {
            return app(ExpireOperationAction::class)->settle($current, $runId, $status);
        }
        if ($current->status->terminal() || isset($current->state['swamp_scheduled_result'])) {
            return $current;
        }

        // Family projections read/clean native secrets outside their own short result transaction.
        $projected = $direct->projection($current)->apply($current, [
            'run_id' => $runId, 'status' => $status, 'started_at' => $run['startedAt'], 'finished_at' => $run['completedAt'],
            'error_code' => in_array($status, ['failed', 'interrupted'], true) ? ($result['error_code'] ?? 'operation.native_failed') : null,
            'result' => $result, 'failure' => app(NativeStepFailure::class)->fromRun($run, $current),
        ]);
        $this->fillStepTimes($projected, $runId, $run);

        return $projected;
    }

    /**
     * The step events carry no times; the terminal history has them for each step of the run and of its nested runs. Best effort:
     * a failed read leaves the times empty and never fails the projection.
     *
     * @param  array<string,mixed>  $run
     */
    private function fillStepTimes(Operation $operation, string $runId, array $run): void
    {
        try {
            $recorded = $this->recordedSteps($run);
            foreach ($this->nestedRuns($runId, is_string($run['startedAt'] ?? null) ? $run['startedAt'] : '') as $nested) {
                $recorded = [...$recorded, ...$this->recordedSteps($this->history($nested))];
            }
            usort($recorded, static fn (array $left, array $right): int => strcmp($left['started_at'], $right['started_at']));
            DB::transaction(function () use ($operation, $runId, $recorded): void {
                $current = Operation::query()->lockForUpdate()->find($operation->id);
                $progress = $current?->state['native_progress'] ?? null;
                if ($current === null || ! is_array($progress) || ($progress['run'] ?? null) !== $runId || ! is_array($progress['steps'] ?? null)) {
                    return;
                }
                $steps = app(NativeRunStepTimes::class)->merge($progress['steps'], $recorded);
                if ($steps === $progress['steps']) {
                    return;
                }
                $state = [...($current->state ?? []), 'native_progress' => [...$progress, 'steps' => $steps]];
                app(OperationStore::class)->change($current, ['state' => $state]);
            });
        } catch (Throwable $exception) {
            Log::warning('The step times of a finished run were not recorded.', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
        }
    }

    /**
     * @param  array<string,mixed>  $run
     * @return list<array{job:string,name:string,started_at:string,finished_at:string|null}>
     */
    private function recordedSteps(array $run): array
    {
        $recorded = [];
        $jobs = is_array($run['jobs'] ?? null) ? $run['jobs'] : [];
        foreach ($jobs as $job) {
            $jobName = is_array($job) ? ($job['name'] ?? null) : null;
            $steps = is_array($job) && is_array($job['steps'] ?? null) ? $job['steps'] : [];
            foreach ($steps as $step) {
                $name = is_array($step) ? ($step['name'] ?? null) : null;
                $started = is_array($step) ? ($step['startedAt'] ?? null) : null;
                $finished = is_array($step) ? ($step['completedAt'] ?? null) : null;
                if (is_string($jobName) && is_string($name) && is_string($started) && NativeRunTimestamp::parse($started) !== null) {
                    $recorded[] = ['job' => $jobName, 'name' => $name, 'started_at' => $started,
                        'finished_at' => is_string($finished) && NativeRunTimestamp::parse($finished) !== null ? $finished : null];
                }
            }
        }

        return $recorded;
    }

    /**
     * The runs a root run started, at any depth: serve lists each nested run with its parent. They started after the root did.
     *
     * @return list<string>
     */
    private function nestedRuns(string $rootId, string $since): array
    {
        $parents = [];
        for ($offset = 0; $offset < 1000; $offset += 200) {
            $reply = $this->read('workflow.run.search', ['since' => $since, 'limit' => 200, 'offset' => $offset]);
            $data = $reply['data'] ?? null;
            $page = is_array($data) && is_array($data['results'] ?? null) ? $data['results'] : [];
            foreach ($page as $row) {
                $id = is_array($row) ? ($row['runId'] ?? null) : null;
                $nested = is_array($row) && is_array($row['parentRun'] ?? null) ? $row['parentRun'] : [];
                $parent = $nested['runId'] ?? null;
                if (is_string($id) && is_string($parent)) {
                    $parents[$id] = $parent;
                }
            }
            if (count($page) < 200) {
                break;
            }
        }
        $found = [];
        $frontier = [$rootId];
        for ($depth = 0; $depth < 4 && $frontier !== []; $depth++) {
            $next = [];
            foreach ($parents as $child => $parent) {
                if (in_array($parent, $frontier, true) && ! in_array($child, $found, true)) {
                    $found[] = $child;
                    $next[] = $child;
                }
            }
            $frontier = $next;
        }

        return array_slice($found, 0, 60);
    }

    /** Records a terminal root run of one Schedule's own workflow as its fire; a running fire waits for its end. */
    public function projectScheduleFire(string $workflowId, string $runId): bool
    {
        $run = $this->history($runId);
        if (($run['id'] ?? null) !== $runId || ($run['workflowId'] ?? null) !== $workflowId || isset($run['parentRun'])
            || ! is_string($run['workflowName'] ?? null) || preg_match('/\Aschedule-([0-9a-f-]{36})\z/D', $run['workflowName'], $name) !== 1) {
            throw $this->unavailable();
        }
        $running = in_array($run['status'] ?? null, ['pending', 'running', 'suspended'], true);
        if (! is_string($run['startedAt'] ?? null) || (! $running && (! is_string($run['completedAt'] ?? null)
            || ! in_array($run['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)))) {
            throw $this->unavailable();
        }
        // A fire is a task from its start; the same task gets the result when the run ends.
        app(ScheduleFires::class)->record($name[1], $run, $running ? null : $this->fireResult($run));

        return ! $running;
    }

    /** The public result of the fire's nested task-command-run, found by the fire's run id.
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function fireResult(array $run): array
    {
        $reply = $this->read('workflow.run.search', ['workflow' => 'task-command-run', 'inputs' => ['fire_run_id' => $run['id']], 'limit' => 1]);
        $data = $reply['data'] ?? null;
        $rows = is_array($data) && is_array($data['results'] ?? null) ? $data['results'] : [];
        $child = is_array($rows[0] ?? null) && is_string($rows[0]['runId'] ?? null) ? $this->history($rows[0]['runId']) : [];
        $result = app(NativeRunResult::class)->fromRun($child);
        if (($child['status'] ?? null) === 'succeeded' && NativeRunResult::stepStatus($result, 'Run the command') === 'succeeded') {
            return ['ready' => true];
        }

        return $run['status'] === 'succeeded' ? ['ready' => true] : ['error_code' => 'schedule.run_failed'];
    }

    /** Root runs that finished since a time, oldest page first, for the once-a-minute scheduled read.
     * @return list<array<string,mixed>>
     */
    public function finishedRuns(string $since): array
    {
        $runs = [];
        for ($offset = 0; $offset < 2000; $offset += 200) {
            $reply = $this->read('workflow.run.search', ['statuses' => ['succeeded', 'failed', 'cancelled', 'interrupted'],
                'since' => $since, 'limit' => 200, 'offset' => $offset]);
            $data = $reply['data'] ?? null;
            $page = is_array($data) ? ($data['results'] ?? null) : null;
            if (! is_array($page) || ! array_is_list($page)) {
                throw $this->unavailable();
            }
            foreach ($page as $run) {
                if (is_array($run)) {
                    $runs[] = ValidatedData::object($run);
                }
            }
            $total = $data['total'] ?? $reply['total'] ?? null;
            if (count($page) < 200 || (is_int($total) && $offset + count($page) >= $total)) {
                break;
            }
        }

        return $runs;
    }

    /** A terminal scheduled root observes existing pending Operations; it never replaces their admission roots.
     * @param  array<string,mixed>  $terminalRun
     */
    public function projectScheduledTerminal(string $workflowId, array $terminalRun): bool
    {
        $direct = app(DirectOperation::class);
        $declaration = $direct->scheduledResultDefinitions()[$workflowId] ?? throw $this->unavailable();
        $runId = $terminalRun['id'] ?? null;
        if (! is_string($runId) || ! Str::isUuid($runId)
            || (isset($terminalRun['status']) && ! in_array($terminalRun['status'], ['succeeded', 'failed', 'cancelled', 'interrupted'], true))) {
            throw $this->unavailable();
        }
        $run = $this->history($runId);
        if (($run['id'] ?? null) !== $runId || ($run['workflowId'] ?? null) !== $workflowId
            || ($run['workflowName'] ?? null) !== $declaration['workflow'] || isset($run['parentRun'])) {
            throw $this->unavailable();
        }
        if (! isset($terminalRun['status']) && in_array($run['status'] ?? null, ['pending', 'running', 'suspended'], true)) {
            return false;
        }
        if (! in_array($run['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)
            || (isset($terminalRun['status']) && $terminalRun['status'] !== $run['status'])
            || ! is_string($run['startedAt'] ?? null) || ! is_string($run['completedAt'] ?? null)) {
            throw $this->unavailable();
        }
        try {
            foreach ([$run['startedAt'], $run['completedAt']] as $time) {
                if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $time) !== 1) {
                    throw $this->unavailable();
                }
            }
            $startedAt = Carbon::parse($run['startedAt']);
            $finishedAt = Carbon::parse($run['completedAt']);
            if ($finishedAt->lessThan($startedAt)) {
                throw $this->unavailable();
            }
        } catch (Throwable) {
            throw $this->unavailable();
        }
        if (isset($declaration['projection'])) {
            // A failed run passes the result its terminal step printed, if any; an automatic-deploy fire records events before its failure assert.
            app($declaration['projection'])->applyScheduled($workflowId, $run, $this->terminalOutput($run, $declaration['terminal_result']));

            return true;
        }
        $result = $this->terminalOutput($run, $declaration['terminal_result']);
        $rows = $result[$declaration['rows'] ?? ''] ?? null;
        if (count($result) !== 1 || ! is_array($rows) || ! array_is_list($rows) || count($rows) > OperationResultFields::MaximumItems) {
            throw $this->unavailable();
        }
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($row) !== 6 || ! is_int($row['operation_id'] ?? null) || $row['operation_id'] < 1
                || ! is_int($row['node_id'] ?? null) || $row['node_id'] < 1 || ! is_string($row['kind'] ?? null)
                || ! is_string($row['root_run_id'] ?? null) || ! Str::isUuid($row['root_run_id'])
                || ! in_array($row['status'] ?? null, ['succeeded', 'failed'], true) || ! is_array($row['result'] ?? null)
                || ($row['result'] !== [] && array_is_list($row['result'])) || isset($seen[$row['operation_id']])) {
                throw $this->unavailable();
            }
            $seen[$row['operation_id']] = true;
        }
        usort($rows, static fn (array $left, array $right): int => $left['operation_id'] <=> $right['operation_id']);
        DB::transaction(function () use ($rows, $direct, $declaration, $runId, $run, $workflowId): void {
            foreach ($rows as $row) {
                $operation = Operation::query()->lockForUpdate()->find($row['operation_id']);
                if ($operation === null || $operation->status->terminal()) {
                    continue;
                }
                if ($operation->kind !== $row['kind'] || $operation->node_id !== $row['node_id']
                    || ($direct->definition($operation->kind)['scheduled_result'] ?? null) !== $declaration) {
                    throw $this->unavailable();
                }
                $direct->assertRun($operation, $row['root_run_id']);
                $previous = $operation->state['swamp_scheduled_result'] ?? null;
                if (is_array($previous) && (($previous['run_id'] ?? null) === $runId
                    || (is_string($previous['started_at'] ?? null) && Carbon::parse($previous['started_at'])->greaterThanOrEqualTo(Carbon::parse($run['startedAt']))))) {
                    continue;
                }
                $operation->forceFill(['state' => [...($operation->state ?? []), 'swamp_scheduled_result' => [
                    'workflow_id' => $workflowId, 'run_id' => $runId, 'native_status' => $run['status'],
                    'started_at' => $run['startedAt'], 'finished_at' => $run['completedAt'],
                ]]])->save();
                $public = $row['result'];
                if ($row['status'] === 'failed' && $public === []) {
                    $public = ['error_code' => 'operation.native_failed'];
                }
                $direct->projection($operation)->apply($operation, [
                    'run_id' => $row['root_run_id'], 'status' => $row['status'],
                    'scheduled_run_id' => $runId, 'scheduled_workflow_id' => $workflowId, 'scheduled_native_status' => $run['status'],
                    'started_at' => $operation->started_at?->toIso8601String() ?? $run['startedAt'], 'finished_at' => $run['completedAt'],
                    'error_code' => $row['status'] === 'failed' ? ($public['error_code'] ?? 'operation.native_failed') : null, 'result' => $public,
                ]);
            }
        });

        return true;
    }

    /** @param array<string,mixed> $run
     * @param  array{job:string,step:string,alternatives?:list<string>,captures?:array<string,array{job:string,step:string,field?:string}>,native?:true,data?:string}  $selector
     * @return array<string,mixed>
     */
    private function terminalOutput(array $run, array $selector): array
    {
        $stdout = null;
        $selected = null;
        $jobs = $run['jobs'] ?? [];
        if (! is_array($jobs)) {
            throw $this->unavailable();
        }
        foreach ($jobs as $job) {
            if (! is_array($job) || ! is_array($job['steps'] ?? null)) {
                throw $this->unavailable();
            }
            if (($job['name'] ?? null) !== $selector['job']) {
                continue;
            }
            foreach ($job['steps'] as $step) {
                if (! is_array($step)) {
                    throw $this->unavailable();
                }
                if (($step['name'] ?? null) === $selector['step']) {
                    $outputs = $step['outputs'] ?? [];
                    $stdout = is_array($outputs) ? ($outputs['stdout'] ?? null) : null;
                    // Swamp inlines only a small step output in the run history; a larger one is read as its data artifact.
                    if ($stdout === null && isset($selector['data'])) {
                        $stdout = $this->artifactStdout($selector['data'], $step);
                    }
                    $selected ??= $step['status'] ?? null;
                } elseif (in_array($step['name'] ?? null, $selector['alternatives'] ?? [], true) && ($step['status'] ?? 'skipped') !== 'skipped') {
                    // The alternative that ran is the end check.
                    $selected = $step['status'];
                }
            }
        }
        $result = [];
        // A kind without a result-reporting step reads Swamp's own run data; its named end check must have succeeded.
        if (($selector['native'] ?? false) === true) {
            if ($run['status'] === 'succeeded' && $selected !== 'succeeded') {
                throw $this->unavailable();
            }
            $result = app(NativeRunResult::class)->fromRun($run);
        } elseif (is_string($stdout) && trim($stdout) !== '') {
            try {
                if (! is_object(json_decode($stdout, flags: JSON_THROW_ON_ERROR))) {
                    throw $this->unavailable();
                }
                $result = ValidatedData::object(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                throw $this->unavailable();
            }
        } elseif ($run['status'] === 'succeeded') {
            throw $this->unavailable();
        }

        foreach ($selector['captures'] ?? [] as $name => $capture) {
            if (array_key_exists($name, $result)) {
                continue;
            }
            foreach ($jobs as $job) {
                if (($job['name'] ?? null) !== $capture['job']) {
                    continue;
                }
                foreach ($job['steps'] as $step) {
                    if (! is_array($step) || ($step['name'] ?? null) !== $capture['step'] || ($step['status'] ?? null) !== 'succeeded' || ! is_array($step['outputs'] ?? null)) {
                        continue;
                    }
                    $outputs = $step['outputs'];
                    if (! isset($capture['field'])) {
                        $result[$name] = $outputs;
                    } elseif (array_key_exists($capture['field'], $outputs)) {
                        $result[$name] = $outputs[$capture['field']];
                    }
                }
            }
        }

        return $result;
    }

    /** The `stdout` of the `output` data artifact a step wrote, for a step whose output the run history did not inline.
     * @param  array<string,mixed>  $step
     */
    private function artifactStdout(string $behavior, array $step): ?string
    {
        $versions = [];
        foreach (is_array($step['dataArtifacts'] ?? null) ? $step['dataArtifacts'] : [] as $artifact) {
            if (is_array($artifact) && ($artifact['name'] ?? null) === 'output' && is_int($artifact['version'] ?? null) && $artifact['version'] > 0) {
                $versions[] = $artifact['version'];
            }
        }
        if (count($versions) !== 1) {
            return null;
        }
        $reply = $this->read('data.get', ['modelIdOrName' => $behavior, 'dataName' => 'output', 'version' => $versions[0]]);
        $content = is_array($reply['data'] ?? null) ? ($reply['data']['content'] ?? null) : null;
        $decoded = is_string($content) ? json_decode($content, true) : $content;

        return is_array($decoded) && is_string($decoded['stdout'] ?? null) ? $decoded['stdout'] : null;
    }

    /** One request following a native terminal event. Receiving its response is not status polling.
     * @return array<string,mixed>
     */
    private function history(string $runId): array
    {
        $reply = $this->read('workflow.history.get', ['workflowIdOrName' => $runId]);
        $run = $reply['data'] ?? null;
        if (! is_array($run)) {
            throw $this->unavailable();
        }

        return ValidatedData::object($run);
    }

    /** @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function read(string $type, array $arguments): array
    {
        $token = config('swamp.read_token');
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        if (! is_string($token) || $token === '' || ! is_string($address) || $address === '' || ! is_string($ca) || $ca === '') {
            throw $this->unavailable();
        }
        $endpoint = new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token);
        $connection = app(SwampConnection::class);
        // A connection kept from an earlier job may have been closed by serve while idle. Then the first use fails on a dead
        // socket, so one new connection answers the same read. Every read here is idempotent. A new connection that fails is a real failure.
        $reused = $connection->holds($endpoint);
        try {
            return $this->exchange($connection, $endpoint, $type, $arguments);
        } catch (SwampTerminalReadUnavailable $exception) {
            if (! $reused) {
                throw $exception;
            }
            Log::info('The kept Swamp read connection was closed; reading on a new one.', ['request_type' => $type, 'exception_type' => ($exception->getPrevious() ?? $exception)::class]);
        }

        return $this->exchange($connection, $endpoint, $type, $arguments);
    }

    /** @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function exchange(SwampConnection $connection, WebSocketEndpoint $endpoint, string $type, array $arguments): array
    {
        $id = bin2hex(random_bytes(16));
        try {
            $socket = $connection->socket($endpoint, 15, $this->socket);
            $socket->send(['type' => $type, 'id' => $id, 'payload' => $arguments]);
            $deadline = microtime(true) + 30;
            while ($socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $payload = $message['payload'] ?? null;
                    if (($message['type'] ?? null) === $type && is_array($payload)) {
                        return ValidatedData::object($payload);
                    }
                    throw $this->unavailable();
                }
            }
        } catch (ResourceOperationException $exception) {
            $connection->release();
            throw $exception;
        } catch (Throwable $exception) {
            $connection->release();
            throw new SwampTerminalReadUnavailable($exception);
        }
        $connection->release();
        throw new SwampTerminalReadUnavailable;
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.terminal_result_unavailable', 'The native terminal result is unavailable.', 503);
    }
}
