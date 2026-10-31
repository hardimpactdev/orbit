<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
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
        $status = $run['status'] === 'interrupted' ? 'failed' : $run['status'];

        return DB::transaction(function () use ($direct, $operation, $runId, $run, $status, $result): Operation {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $direct->assertRun($current, $runId);
            if ($current->status->terminal()) {
                return $current;
            }

            return $direct->projection($current)->apply($current, [
                'run_id' => $runId, 'status' => $status, 'started_at' => $run['startedAt'], 'finished_at' => $run['completedAt'],
                'error_code' => $status === 'failed' ? ($result['error_code'] ?? 'operation.native_failed') : null,
                'result' => $result,
            ]);
        });
    }

    /** One request following a native terminal event. Receiving its response is not status polling.
     * @return array<string,mixed>
     */
    private function history(string $runId): array
    {
        $token = config('swamp.run_token');
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
