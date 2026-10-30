<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Native submission recovery is one history read and at most one same-key resubmission. */
final readonly class StartSwampRun
{
    public function __construct(private WebSocketClient $socket) {}

    public function start(Operation $operation): void
    {
        $declaration = app(DirectOperation::class)->definition($operation->kind);
        if ($declaration === null || ! DirectOperation::owns($operation)) {
            throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
        }
        $operation->refresh();
        if (isset($operation->state['swamp_run_id'])) {
            return;
        }
        $workflow = $declaration['workflow'];
        $token = config('swamp.run_token');
        if (! is_string($token) || $token === '') {
            throw new ResourceOperationException('swamp.start_unavailable', 'The workflow start credential is not configured.', 503);
        }
        $endpoint = new WebSocketEndpoint(ValidatedData::string(config('swamp.address')), 443, 'swamp.orbit', '/', ValidatedData::string(config('swamp.ca_path')), $token);
        $key = 'operation:'.$operation->id;
        $inputs = ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id];
        $payload = ['workflowIdOrName' => $workflow, 'inputs' => $inputs, 'runtimeTags' => ['orbit_start_key' => $key]];
        $started = microtime(true);
        $outcome = 'uncertain';
        try {
            try {
                $runId = ValidatedData::string($this->request($endpoint, 'workflow.run', $payload, 'start')['run_id']);
                $outcome = 'started';
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'swamp.start_uncertain') {
                    $outcome = 'refused';
                    throw $exception;
                }
                $history = $this->request($endpoint, 'workflow.run.search', [
                    'workflow' => $workflow, 'inputs' => ['operation_id' => (string) $operation->id],
                    'tags' => ['orbit_start_key' => $key], 'limit' => 2,
                ], 'history');
                $runs = $history['results'] ?? null;
                if (! is_array($runs) || ! array_is_list($runs) || count($runs) > 1 || ($history['total'] ?? null) !== count($runs)) {
                    throw $this->uncertain();
                }
                if ($runs === []) {
                    $runId = ValidatedData::string($this->request($endpoint, 'workflow.run', $payload, 'resubmit')['run_id']);
                    $outcome = 'resubmitted';
                } else {
                    $run = $runs[0];
                    if (! is_array($run) || ($run['workflowName'] ?? null) !== $workflow
                        || ! in_array($run['status'] ?? null, ['pending', 'running', 'suspended', 'succeeded'], true)
                        || ! is_array($run['tags'] ?? null) || ($run['tags']['orbit_start_key'] ?? null) !== $key
                        || ! is_string($run['runId'] ?? null) || $run['runId'] === ''
                        || ! is_array($run['inputs'] ?? null) || array_diff_key($inputs, $run['inputs']) !== []
                        || array_replace($inputs, $run['inputs']) !== $inputs) {
                        throw $this->uncertain();
                    }
                    $runId = $run['runId'];
                    $outcome = 'recovered';
                }
            }
            $operation->refresh();
            if (isset($operation->state['swamp_run_id'])) {
                app(DirectOperation::class)->assertRun($operation, $runId);
            }
            $operation->update(['dispatched_at' => now(), 'state' => [...($operation->state ?? []), 'swamp_run_id' => $runId]]);
        } catch (ResourceOperationException $exception) {
            $outcome = $exception->errorCode === 'swamp.start_refused' ? 'refused' : 'uncertain';
            throw $exception;
        } finally {
            Log::info('Swamp direct start', ['operation_id' => $operation->id, 'workflow' => $workflow,
                'outcome' => $outcome, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(WebSocketEndpoint $endpoint, string $type, array $payload, string $phase): array
    {
        $id = bin2hex(random_bytes(16));
        $stage = 'connect';
        try {
            $this->socket->connect($endpoint, 15);
            $stage = 'send';
            $this->socket->send(['type' => $type, 'id' => $id, 'payload' => $payload]);
            $stage = 'receive';
            $deadline = microtime(true) + 30;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                foreach ($this->socket->receive(min(0.2, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $event = $message['event'] ?? null;
                    if ($type === 'workflow.run' && ($message['type'] ?? null) === 'event' && is_array($event)
                        && ($event['kind'] ?? null) === 'started' && ! isset($event['parentRunId'])
                        && ($event['workflowName'] ?? null) === $payload['workflowIdOrName']
                        && is_string($event['runId'] ?? null) && $event['runId'] !== '') {
                        return ['run_id' => $event['runId']];
                    }
                    if ($type === 'workflow.run.search' && ($message['type'] ?? null) === $type) {
                        $response = ValidatedData::object($message['payload'] ?? null);

                        return [...ValidatedData::object($response['data'] ?? null), 'total' => $response['total'] ?? null];
                    }
                    if (($message['type'] ?? null) === 'error') {
                        throw $type === 'workflow.run'
                            ? new ResourceOperationException('swamp.start_refused', 'Swamp refused the workflow start. Inspect Swamp before another admission.', 503)
                            : $this->uncertain();
                    }
                }
            }
            throw $this->uncertain();
        } catch (Throwable $exception) {
            Log::warning('Swamp direct start response unavailable', ['phase' => $phase, 'stage' => $stage,
                'exception_type' => $exception::class, 'transport_code' => $exception->getCode()]);
            throw $exception instanceof ResourceOperationException ? $exception : $this->uncertain();
        } finally {
            $this->socket->close();
        }
    }

    private function uncertain(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.start_uncertain', 'Swamp did not confirm its start. Inspect native run history before another admission.', 503);
    }
}
