<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** One synchronous native submission. Unconfirmed starts are terminal and never resubmitted. */
final readonly class StartSwampRun
{
    public function __construct(private WebSocketClient $socket) {}

    public function start(Operation $operation): void
    {
        $started = microtime(true);
        $outcome = 'unavailable';
        $accepted = false;
        try {
            if (! $this->begin($operation)) {
                return;
            }
            $declaration = app(DirectOperation::class)->definition($operation->kind);
            if ($declaration === null) {
                throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
            }
            $endpoint = $this->endpoint();
            $response = $this->request($endpoint, [
                'workflowIdOrName' => $declaration['workflow'],
                'inputs' => app(DirectOperation::class)->workflowInputs($operation),
                'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id],
            ]);
            $accepted = true;
            $this->bind($operation, $response['run_id']);
            if ($response['failed']) {
                throw new ResourceOperationException('operation.native_dispatch_failed', 'The native workflow ended before its start completed.', 503);
            }
            $outcome = 'started';
            if (isset($declaration['terminal_result'], $response['terminal_run'])) {
                try {
                    app(SwampCommandStep::class)->projectTerminal($operation, $response['terminal_run'], $declaration['terminal_result']);
                } catch (Throwable $exception) {
                    Log::warning('Swamp accepted start terminal result unavailable', [
                        'operation_id' => $operation->id, 'exception_type' => $exception::class,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            $safe = $exception instanceof ResourceOperationException
                ? $exception : ($accepted ? $this->uncertain()
                    : new ResourceOperationException('swamp.start_unavailable', 'The native workflow start is unavailable.', 503));
            $outcome = $safe->errorCode;
            $this->fail($operation, $safe->errorCode);
            throw new ResourceOperationException($safe->errorCode, $safe->getMessage(), $safe->status, details: ['operation_id' => $operation->id]);
        } finally {
            Log::info('Swamp direct start', ['operation_id' => $operation->id,
                'outcome' => $outcome, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
        }
    }

    /** The lifecycle transition also prevents a second caller from submitting this intent. */
    private function begin(Operation $operation): bool
    {
        return DB::transaction(function () use ($operation): bool {
            $this->lock($operation);
            if ($operation->status !== OperationStatus::Queued) {
                return false;
            }
            if (isset($operation->state['swamp_run_id'])) {
                app(OperationStore::class)->change($operation, ['status' => OperationStatus::Running, 'started_at' => $operation->started_at ?? now(), 'error_code' => null]);

                return false;
            }
            if ($operation->cancel_requested_at !== null) {
                app(OperationStore::class)->change($operation, ['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'error_code' => null]);

                return false;
            }
            app(OperationStore::class)->change($operation, ['status' => OperationStatus::Running, 'started_at' => now(), 'error_code' => null]);

            return true;
        });
    }

    private function endpoint(): WebSocketEndpoint
    {
        $token = config('swamp.run_token');
        $address = config('swamp.address');
        $caPath = config('swamp.ca_path');
        if (! is_string($token) || $token === '' || ! is_string($address) || trim($address) === '' || ! is_string($caPath) || trim($caPath) === '') {
            throw new ResourceOperationException('swamp.start_unavailable', 'The native workflow start is not configured.', 503);
        }

        return new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $caPath, $token);
    }

    private function bind(Operation $operation, string $runId): void
    {
        DB::transaction(function () use ($operation, $runId): void {
            $this->lock($operation);
            if (isset($operation->state['swamp_run_id'])) {
                app(DirectOperation::class)->assertRun($operation, $runId);
            }
            app(OperationStore::class)->change($operation, ['dispatched_at' => now(), 'state' => [...($operation->state ?? []), 'swamp_run_id' => $runId]]);
        });
    }

    private function fail(Operation $operation, string $code): void
    {
        DB::transaction(function () use ($operation, $code): void {
            $this->lock($operation);
            if ($operation->status->terminal()) {
                return;
            }
            app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(),
                'error_code' => $code, 'current_step' => null, 'failed_step' => null, 'lock_key' => null]);
        });
    }

    private function lock(Operation $operation): void
    {
        $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
    }

    /** @param array<string,mixed> $payload
     * @return array{run_id:string,failed:bool,terminal_run:array{id:string,status:string}|null}
     */
    private function request(WebSocketEndpoint $endpoint, array $payload): array
    {
        $id = bin2hex(random_bytes(16));
        $stage = 'connect';
        try {
            $this->socket->connect($endpoint, 15);
            $stage = 'send';
            $this->socket->send(['type' => 'workflow.run', 'id' => $id, 'payload' => $payload]);
            $stage = 'receive';
            $deadline = microtime(true) + 30;
            while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
                $confirmed = null;
                $terminals = [];
                foreach ($this->socket->receive(min(0.2, $remaining)) as $message) {
                    if (($message['id'] ?? null) !== $id) {
                        continue;
                    }
                    $event = $message['event'] ?? null;
                    if (($message['type'] ?? null) === 'event' && is_array($event) && ! isset($event['parentRunId'])
                        && ($event['workflowName'] ?? null) === $payload['workflowIdOrName']
                        && is_string($event['runId'] ?? null) && Str::isUuid($event['runId'])
                        && in_array($event['kind'] ?? null, ['started', 'failed', 'cancelled'], true)) {
                        $confirmed ??= ['run_id' => $event['runId'], 'failed' => $event['kind'] !== 'started'];
                    }
                    $run = is_array($event) ? ($event['run'] ?? null) : null;
                    if (($message['type'] ?? null) === 'event' && is_array($event) && ! isset($event['parentRunId'])
                        && in_array($event['kind'] ?? null, ['completed', 'cancelled'], true) && is_array($run)
                        && ($run['workflowName'] ?? null) === $payload['workflowIdOrName']
                        && is_string($run['id'] ?? null) && Str::isUuid($run['id'])
                        && in_array($run['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
                        $terminals[$run['id']] = ['id' => $run['id'], 'status' => $run['status']];
                    }
                    if (($message['type'] ?? null) === 'error' && $confirmed === null) {
                        $error = is_array($message['error'] ?? null) ? $message['error'] : $message;
                        $redact = static function (mixed $value, int $limit) use ($endpoint): string {
                            if (! is_string($value) || strlen($value) > 32768 || preg_match('//u', $value) !== 1) {
                                return '[withheld]';
                            }

                            $safe = app(LogRedactor::class)->redact($value, array_filter([$endpoint->bearerToken]));

                            return Str::limit(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $safe) ?? '[withheld]', $limit);
                        };
                        Log::warning('Swamp direct start refused', [
                            'workflow' => $payload['workflowIdOrName'], 'remote_type' => 'error',
                            'remote_code' => $redact($error['code'] ?? null, 100),
                            'remote_message' => $redact($error['message'] ?? null, 2000),
                        ]);
                        throw new ResourceOperationException('swamp.start_refused', 'Swamp refused the workflow start. Inspect Swamp before another admission.', 503);
                    }
                }
                if ($confirmed !== null) {
                    return [...$confirmed, 'terminal_run' => $terminals[$confirmed['run_id']] ?? null];
                }
            }
            throw $this->uncertain();
        } catch (Throwable $exception) {
            Log::warning('Swamp direct start response unavailable', ['stage' => $stage, 'exception_type' => $exception::class]);
            if ($exception instanceof ResourceOperationException) {
                throw $exception;
            }
            throw $stage === 'connect'
                ? new ResourceOperationException('swamp.start_unavailable', 'The native workflow start could not connect.', 503)
                : $this->uncertain();
        } finally {
            $this->socket->close();
        }
    }

    private function uncertain(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.start_uncertain', 'Swamp did not confirm its start. A native run may exist; inspect Swamp before another admission.', 503);
    }
}
