<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationResultFields;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /** Called by the shared native terminal subscriber, never by a family step or a poller.
     * @param  array<string,mixed>  $terminalRun  Native event.run, or its bound id after a late attach miss.
     * @param  array{job:string,step:string}  $selector  The fixed family terminal_result declaration.
     */
    public function projectTerminal(Operation $operation, array $terminalRun, array $selector): Operation
    {
        $direct = app(DirectOperation::class);
        $runId = $terminalRun['id'] ?? null;
        $hasStatus = array_key_exists('status', $terminalRun);
        $terminalStatuses = ['succeeded', 'failed', 'cancelled', 'interrupted'];
        if (! is_string($runId) || ($hasStatus && ! in_array($terminalRun['status'], $terminalStatuses, true))) {
            throw $this->unavailable();
        }
        $direct->assertRun($operation, $runId);
        $operation->refresh();
        if ($operation->status->terminal()) {
            return $operation;
        }
        $run = $this->history($runId);
        $inputs = $run['inputs'] ?? null;
        $workflow = $direct->definition($operation->kind)['workflow'] ?? throw $this->unavailable();
        if (($run['id'] ?? null) !== $runId || ($run['workflowName'] ?? null) !== $workflow
            || ! in_array($run['status'] ?? null, $terminalStatuses, true)
            || ($hasStatus && $run['status'] !== $terminalRun['status']) || ! is_array($inputs)
            || ($inputs['operation_id'] ?? null) !== $operation->id || ($inputs['node_id'] ?? null) !== $operation->node_id
            || ($inputs['kind'] ?? null) !== $operation->kind) {
            throw $this->unavailable();
        }
        $result = $this->terminalOutput($run, $selector);
        $status = $run['status'];
        if (in_array($status, ['failed', 'interrupted'], true) && $result === []) {
            $result = ['error_code' => 'operation.native_failed'];
        }

        return DB::transaction(function () use ($direct, $operation, $runId, $run, $status, $result): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $direct->assertRun($current, $runId);
            if ($current->status->terminal() || isset($current->state['swamp_scheduled_result'])) {
                return $current;
            }

            return $direct->projection($current)->apply($current, [
                'run_id' => $runId, 'status' => $status, 'started_at' => $run['startedAt'], 'finished_at' => $run['completedAt'],
                'error_code' => in_array($status, ['failed', 'interrupted'], true) ? ($result['error_code'] ?? 'operation.native_failed') : null,
                'result' => $result,
            ]);
        });
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
            app($declaration['projection'])->applyScheduled($workflowId, $run, $run['status'] === 'succeeded' ? $this->terminalOutput($run, $declaration['terminal_result']) : []);

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
     * @param  array{job:string,step:string}  $selector
     * @return array<string,mixed>
     */
    private function terminalOutput(array $run, array $selector): array
    {
        $stdout = null;
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
                }
            }
        }
        $result = [];
        if (is_string($stdout) && trim($stdout) !== '') {
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

        return $result;
    }

    /** One request following a native terminal event. Receiving its response is not status polling.
     * @return array<string,mixed>
     */
    private function history(string $runId): array
    {
        $token = config('swamp.read_token');
        $address = config('swamp.address');
        $ca = config('swamp.ca_path');
        if (! is_string($token) || $token === '' || ! is_string($address) || $address === '' || ! is_string($ca) || $ca === '') {
            throw $this->unavailable();
        }
        $id = bin2hex(random_bytes(16));
        try {
            $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 15);
            $this->socket->send(['type' => 'workflow.history.get', 'id' => $id, 'payload' => ['workflowIdOrName' => $runId]]);
            $deadline = microtime(true) + 30;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(1.0, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $payload = $message['payload'] ?? null;
                    $run = is_array($payload) ? ($payload['data'] ?? null) : null;
                    if (($message['type'] ?? null) === 'workflow.history.get' && is_array($run)) {
                        return ValidatedData::object($run);
                    }
                    throw $this->unavailable();
                }
            }
        } catch (Throwable) {
            throw $this->unavailable();
        } finally {
            $this->socket->close();
        }
        throw $this->unavailable();
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.terminal_result_unavailable', 'The native terminal result is unavailable.', 503);
    }
}
