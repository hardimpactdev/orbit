<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/** Follows bound native roots for their progress and asks the reconciler to settle one when its run ends. It never submits, resumes or cancels native work. */
final class SwampRunSubscriber
{
    /** @var array<int,array{run:string,workflow:string,request:?string,attached:bool}> */
    private array $following = [];

    /** @var array<string,int> */
    private array $requests = [];

    /** @var array<int,string> Terminal notices queued during this connection. */
    private array $queued = [];

    private float $connectionBackoff = 0.0;

    private float $connectedAt = 0.0;

    private bool $started = false;

    private float $nextConnection = 0.0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure():float)|null $clock */
    public function __construct(private readonly WebSocketClient $socket, private readonly LoggerInterface $log, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** One snapshot at startup or after the record channel rejoins. */
    public function recover(): void
    {
        $this->queued = [];
        $kinds = array_keys(app(DirectOperation::class)->definitions());
        if ($kinds === []) {
            return;
        }
        foreach (Operation::query()->whereIn('kind', $kinds)->where(fn ($query) => $query->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])
            ->orWhere(fn ($query) => $query->where('error_code', 'operation.timed_out')
                ->whereNotNull('state->native_timeout')->whereNull('state->native_timeout->native_terminal_status')))
            ->lazyById() as $operation) {
            $this->follow($operation->id);
        }
    }

    /** A private record notice contains an ID only. Identity and selection come from the current record. */
    public function follow(int $operationId): void
    {
        $operation = Operation::query()->find($operationId);
        if ($operation === null || ($operation->status->terminal() && ! ExpireOperationAction::expiredUnsettled($operation))) {
            $request = $this->following[$operationId]['request'] ?? null;
            if ($request !== null) {
                unset($this->requests[$request]);
            }
            unset($this->following[$operationId]);

            return;
        }
        $definition = app(DirectOperation::class)->definition($operation->kind);
        $run = $operation->state['swamp_run_id'] ?? null;
        if ($definition === null || ! app(DirectOperation::class)->usesNativeTerminal($operation) || ! is_string($run) || ! Str::isUuid($run)
            || ($this->following[$operationId]['run'] ?? null) === $run || ($this->queued[$operationId] ?? null) === $run) {
            return;
        }
        $old = $this->following[$operationId]['request'] ?? null;
        if ($old !== null) {
            unset($this->requests[$old]);
        }
        $this->following[$operationId] = ['run' => $run, 'workflow' => $definition['workflow'], 'request' => null, 'attached' => false];
    }

    /** One pass beside the existing Gateway observer's Reverb receive loop. */
    public function pass(): void
    {
        if (! $this->started) {
            $this->recover();
            $this->started = true;
        }
        if ($this->following === [] || ($this->clock)() < $this->nextConnection) {
            return;
        }
        try {
            if (! $this->socket->isConnected()) {
                $address = config('swamp.address');
                $ca = config('swamp.ca_path');
                $token = config('swamp.run_token');
                if (! is_string($address) || $address === '' || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
                    return;
                }
                $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 0.5);
                $this->connectedAt = ($this->clock)();
            }
            foreach ($this->following as $operationId => $entry) {
                if ($entry['request'] !== null) {
                    continue;
                }
                $id = bin2hex(random_bytes(16));
                $this->requests[$id] = $operationId;
                $this->following[$operationId]['request'] = $id;
                $this->socket->send(['type' => 'run.attach', 'id' => $id, 'payload' => ['runId' => $entry['run'], 'afterSeq' => 0]]);
            }
            foreach ($this->socket->receive(0.0) as $message) {
                $this->receive($message);
            }
            if (! $this->socket->isConnected()) {
                $this->close();
                $this->scheduleReconnect();
            } elseif (($this->clock)() - $this->connectedAt >= 60.0) {
                $this->connectionBackoff = 0.0;
            }
        } catch (Throwable $exception) {
            // Remote frames and exception text can contain inputs. Log identity and type only.
            $this->log->warning('The native terminal observer connection is unavailable.', ['exception_type' => $exception::class]);
            $this->close();
            $this->scheduleReconnect();
        }
    }

    private function scheduleReconnect(): void
    {
        $this->connectionBackoff = min(60.0, max(1.0, $this->connectionBackoff * 2));
        $this->nextConnection = ($this->clock)() + $this->connectionBackoff;
    }

    public function close(): void
    {
        $this->socket->close();
        $this->requests = [];
        $this->queued = [];
        foreach ($this->following as $operationId => $_entry) {
            $this->following[$operationId]['request'] = null;
            $this->following[$operationId]['attached'] = false;
        }
    }

    /** @param array<string,mixed> $message */
    private function receive(array $message): void
    {
        $id = $message['id'] ?? null;
        $operationId = is_string($id) ? ($this->requests[$id] ?? null) : null;
        if ($operationId === null) {
            return;
        }
        $entry = $this->following[$operationId];
        $payload = $message['payload'] ?? null;
        $type = $message['type'] ?? null;
        if ($type === 'run.attached' && is_array($payload) && ($payload['runId'] ?? null) === $entry['run']
            && in_array($payload['kind'] ?? null, ['workflow-run', 'workflow-resume'], true)) {
            $this->following[$operationId]['attached'] = true;

            return;
        }
        if ($type === 'error') {
            $error = is_array($message['error'] ?? null) ? $message['error'] : $message;
            if (($error['code'] ?? null) === 'not_found') {
                $this->project($operationId);
            } else {
                $this->log->warning('The native terminal observer attach was refused.', ['operation_id' => $operationId]);
                unset($this->following[$operationId], $this->requests[$id]);
            }

            return;
        }
        if ($type === 'run.interrupted') {
            if (is_array($payload) && ($payload['runId'] ?? null) === $entry['run']) {
                $this->project($operationId);
            }

            return;
        }
        if ($type === 'run.elsewhere') {
            $this->log->warning('The native terminal observer cannot attach on this Serve.', ['operation_id' => $operationId]);
            unset($this->following[$operationId], $this->requests[$id]);

            return;
        }
        $event = $message['event'] ?? null;
        $run = is_array($event) ? ($event['run'] ?? null) : null;
        if ($type === 'event' && $entry['attached'] && is_array($event) && is_string($event['parentRunId'] ?? null) && Str::isUuid($event['parentRunId'])
            && is_int($event['seq'] ?? null) && $event['seq'] > 0
            && in_array($event['kind'] ?? null, ['completed', 'cancelled'], true) && is_array($run)
            && in_array($run['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            // The root stays followed. The existing reconciler also records finished child evidence.
            ReconcileSwampOperations::dispatch();
        }
        if ($type === 'event' && $entry['attached'] && is_array($event) && is_int($event['seq'] ?? null) && $event['seq'] > 0
            && (! isset($event['parentRunId']) || $event['parentRunId'] === $entry['run'])) {
            app(NativeRunProgress::class)->apply($operationId, $entry['run'], $event);
        }

        if ($type !== 'event' || ! $entry['attached'] || ! is_array($event) || isset($event['parentRunId'])
            || ! is_int($event['seq'] ?? null) || $event['seq'] < 1
            || ! in_array($event['kind'] ?? null, ['completed', 'cancelled'], true) || ! is_array($run)
            || ($run['id'] ?? null) !== $entry['run'] || ($run['workflowName'] ?? null) !== $entry['workflow']
            || ! is_string($run['status'] ?? null) || ! in_array($run['status'], ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            return;
        }
        $this->project($operationId);
    }

    private function project(int $operationId): void
    {
        $entry = $this->following[$operationId];
        if (($this->queued[$operationId] ?? null) === $entry['run']) {
            $current = Operation::query()->find($operationId);
            if ($current === null || ! ExpireOperationAction::expiredUnsettled($current)) {
                return;
            }
        }
        try {
            ReconcileSwampOperations::dispatch();
            $this->queued[$operationId] = $entry['run'];
        } catch (Throwable $exception) {
            // The reconciler's next scheduled pass settles the run.
            $this->log->warning('The Operation reconcile request is unavailable.', ['operation_id' => $operationId, 'exception_type' => $exception::class]);

            return;
        }
        unset($this->following[$operationId]);
        if ($entry['request'] !== null) {
            unset($this->requests[$entry['request']]);
        }
    }
}
