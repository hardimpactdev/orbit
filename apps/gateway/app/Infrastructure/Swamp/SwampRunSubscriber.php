<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Jobs\Operations\ProjectSwampTerminal;
use App\Jobs\Operations\RecoverUnboundSwampRun;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/** Observes bound native roots. It never submits, resumes, cancels or polls native work. */
final class SwampRunSubscriber
{
    /** @var array<int,array{run:string,workflow:string,request:?string,attached:bool}> */
    private array $following = [];

    /** @var array<string,int> */
    private array $requests = [];

    /** @var array<string,true> One lookup per uncertain start or timeout, never a timer retry. */
    private array $lookedUp = [];

    /** @var array<int,int> Gateway deadlines, populated by committed records and startup recovery. */
    private array $deadlines = [];

    /** @var array<int,string> Terminal notices queued during this connection. */
    private array $queued = [];

    private float $connectionBackoff = 0.0;

    private float $connectedAt = 0.0;

    private bool $started = false;

    private float $nextConnection = 0.0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @param (Closure():float)|null $clock */
    public function __construct(private readonly WebSocketClient $socket, private readonly LoggerInterface $log, ?Closure $clock = null, private readonly ?SwampScheduledResults $scheduled = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** One snapshot at startup or after the record channel rejoins; never a status poll. */
    public function recover(): void
    {
        $this->queued = [];
        $kinds = array_keys(app(DirectOperation::class)->definitions());
        if ($kinds === []) {
            return;
        }
        foreach (Operation::query()->whereIn('kind', $kinds)->where(fn ($query) => $query->whereIn('status', [OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting])
            ->orWhere(fn ($query) => $query->where('error_code', 'operation.timed_out')->whereNotNull('lock_key')))
            ->lazyById() as $operation) {
            $this->follow($operation->id);
        }
    }

    /** A private record notice contains an ID only. Identity and selection come from the current record. */
    public function follow(int $operationId): void
    {
        $operation = Operation::query()->find($operationId);
        if ($operation !== null) {
            $operation = $this->expire($operation);
        }
        if ($operation === null || ($operation->status->terminal() && ! ExpireOperationAction::awaitsSettlement($operation))) {
            $request = $this->following[$operationId]['request'] ?? null;
            if ($request !== null) {
                unset($this->requests[$request]);
            }
            unset($this->following[$operationId], $this->deadlines[$operationId]);

            return;
        }
        $definition = app(DirectOperation::class)->definition($operation->kind);
        $deadline = $definition === null || ExpireOperationAction::awaitsSettlement($operation) || isset($this->queued[$operationId]) ? null : OperationRunBudgets::deadline($operation);
        if ($deadline !== null) {
            $this->deadlines[$operationId] = $deadline->getTimestamp();
        }
        $lookup = (ExpireOperationAction::awaitsSettlement($operation) ? 'timeout:' : 'start:').$operationId;
        if ($definition !== null && app(DirectOperation::class)->usesNativeTerminal($operation)
            && ! isset($operation->state['swamp_run_id']) && ($operation->error_code === 'swamp.start_uncertain' || ExpireOperationAction::awaitsSettlement($operation))
            && ! isset($this->lookedUp[$lookup])) {
            $this->lookedUp[$lookup] = true;
            try {
                Bus::dispatch(new RecoverUnboundSwampRun($operation->id));
                $operation->refresh();
            } catch (Throwable $exception) {
                $this->log->warning('The native start lookup is unavailable.', ['operation_id' => $operationId, 'exception_type' => $exception::class]);
            }
        }
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
        OperationRunBudgets::duringTick(fn () => $this->receivePass());
    }

    private function receivePass(): void
    {
        if (! $this->started) {
            $this->recover();
            $this->started = true;
        }
        foreach ($this->deadlines as $operationId => $deadline) {
            if ($deadline > ($this->clock)()) {
                continue;
            }
            $operation = Operation::query()->find($operationId);
            if ($operation !== null) {
                $this->expire($operation);
            }
            unset($this->deadlines[$operationId]);
            $this->follow($operationId);
        }
        $this->scheduled?->pass();
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

    /** At a bound deadline, let the existing single history read arbitrate before any failure alert. */
    private function expire(Operation $operation): Operation
    {
        $deadline = OperationRunBudgets::deadline($operation);
        $run = $operation->state['swamp_run_id'] ?? null;
        if (! $operation->status->terminal() && $deadline !== null && ! $deadline->isFuture()
            && is_string($run) && Str::isUuid($run) && app(DirectOperation::class)->usesNativeTerminal($operation)) {
            if (! isset($this->queued[$operation->id])) {
                Bus::dispatch(new ProjectSwampTerminal($operation->id, $run, null, atDeadline: true));
                $this->queued[$operation->id] = $run;
            }

            return $operation->refresh();
        }

        return app(ExpireOperationAction::class)->execute($operation);
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
                $this->project($operationId, null);
            } else {
                $this->log->warning('The native terminal observer attach was refused.', ['operation_id' => $operationId]);
                unset($this->following[$operationId], $this->requests[$id]);
            }

            return;
        }
        if ($type === 'run.interrupted') {
            if (is_array($payload) && ($payload['runId'] ?? null) === $entry['run']) {
                $this->project($operationId, null);
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
        if ($type !== 'event' || ! $entry['attached'] || ! is_array($event) || isset($event['parentRunId'])
            || ! is_int($event['seq'] ?? null) || $event['seq'] < 1
            || ! in_array($event['kind'] ?? null, ['completed', 'cancelled'], true) || ! is_array($run)
            || ($run['id'] ?? null) !== $entry['run'] || ($run['workflowName'] ?? null) !== $entry['workflow']
            || ! is_string($run['status'] ?? null) || ! in_array($run['status'], ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            return;
        }
        $this->project($operationId, $run['status']);
    }

    private function project(int $operationId, ?string $status): void
    {
        $entry = $this->following[$operationId];
        if (($this->queued[$operationId] ?? null) === $entry['run']) {
            $current = Operation::query()->find($operationId);
            if ($current === null || ! ExpireOperationAction::awaitsSettlement($current)) {
                return;
            }
        }
        try {
            Bus::dispatch(new ProjectSwampTerminal($operationId, $entry['run'], $status));
            $this->queued[$operationId] = $entry['run'];
        } catch (Throwable $exception) {
            // A later native notice or explicit recovery may deliver again; elapsed time never reads history.
            $this->log->warning('The native terminal projection is unavailable.', ['operation_id' => $operationId, 'exception_type' => $exception::class]);

            return;
        }
        unset($this->following[$operationId]);
        if ($entry['request'] !== null) {
            unset($this->requests[$entry['request']]);
        }
    }
}
