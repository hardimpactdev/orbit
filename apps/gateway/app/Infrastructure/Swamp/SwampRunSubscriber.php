<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Jobs\Operations\ProjectSwampTerminal;
use App\Models\Operation;
use Closure;
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

    private readonly SwampScheduledResults $scheduled;

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
        $this->scheduled = new SwampScheduledResults($socket, $log);
    }

    /** One snapshot at startup or after the record channel rejoins; never a status poll. */
    public function recover(): void
    {
        $kinds = array_keys(array_filter(app(DirectOperation::class)->definitions(), static fn (array $definition): bool => isset($definition['terminal_result'])));
        if ($kinds === []) {
            return;
        }
        foreach (Operation::query()->whereIn('kind', $kinds)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])
            ->whereNotNull('state->swamp_run_id')->lazyById() as $operation) {
            $this->follow($operation->id);
        }
    }

    /** A private record notice contains an ID only. Identity and selection come from the current record. */
    public function follow(int $operationId): void
    {
        $operation = Operation::query()->find($operationId);
        if ($operation === null || $operation->status->terminal()) {
            $request = $this->following[$operationId]['request'] ?? null;
            if ($request !== null) {
                unset($this->requests[$request]);
            }
            unset($this->following[$operationId]);

            return;
        }
        $definition = app(DirectOperation::class)->definition($operation->kind);
        $run = $operation->state['swamp_run_id'] ?? null;
        if (! isset($definition['terminal_result']) || ! is_string($run) || ! Str::isUuid($run)
            || ($this->following[$operationId]['run'] ?? null) === $run) {
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
        if (($this->following === [] && ! $this->scheduled->enabled()) || ($this->clock)() < $this->nextConnection) {
            return;
        }
        try {
            if (! $this->socket->isConnected()) {
                $address = config('swamp.address');
                $ca = config('swamp.ca_path');
                $token = config('swamp.read_token');
                if (! is_string($address) || $address === '' || ! is_string($ca) || $ca === '' || ! is_string($token) || $token === '') {
                    return;
                }
                $this->socket->connect(new WebSocketEndpoint($address, 443, 'swamp.orbit', '/', $ca, $token), 0.5);
                $this->connectedAt = ($this->clock)();
            }
            $this->scheduled->subscribe();
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
                if (! $this->scheduled->receive($message)) {
                    $this->receive($message);
                }
            }
            $this->scheduled->attach();
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
        $this->scheduled->disconnected();
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
        try {
            ProjectSwampTerminal::dispatchSync($operationId, $entry['run'], $status);
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
