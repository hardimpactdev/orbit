<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Jobs\Operations\ProjectSwampScheduledTerminal;
use App\Jobs\Operations\ProjectSwampScheduleFire;
use App\Models\Schedule;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/** Read-only commit-feed discovery owned by the existing shared Gateway observer. */
final class SwampScheduledResults
{
    /** Checkpoint marker of a root run of one Schedule's own workflow. */
    private const Fire = 'schedule:';

    private ?string $feedRequest = null;

    private bool $refused = false;

    /** @var array<string,string> request ID => workflow UUID */
    private array $readRequests = [];

    /** @var array<string,true> */
    private array $readable = [];

    private bool $loaded = false;

    private int $cursor = 0;

    /** @var array<string,string> run UUID => declared workflow UUID */
    private array $runs = [];

    private float $nextConnection = 0.0;

    private float $backoff = 0.0;

    private float $connectedAt = 0.0;

    private readonly Closure $clock;

    /** @param (Closure():float)|null $clock */
    public function __construct(private readonly WebSocketClient $socket, private readonly LoggerInterface $log, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function pass(): void
    {
        if (! $this->enabled() || $this->refused || ($this->clock)() < $this->nextConnection) {
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
            $this->subscribe();
            foreach ($this->socket->receive(0.0) as $message) {
                $this->receive($message);
            }
            if (! $this->socket->isConnected()) {
                throw new WebSocketException('The native discovery feed disconnected.');
            }
            if (($this->clock)() - $this->connectedAt >= 60.0) {
                $this->backoff = 0.0;
            }
        } catch (Throwable $exception) {
            $this->log->warning('The shared native discovery connection is unavailable.', ['exception_type' => $exception::class]);
            $this->close();
            $this->backoff = min(60.0, max(1.0, $this->backoff * 2));
            $this->nextConnection = ($this->clock)() + $this->backoff;
        }
    }

    public function enabled(): bool
    {
        return app(DirectOperation::class)->scheduledResultDefinitions() !== [];
    }

    public function subscribe(): void
    {
        if (! $this->enabled() || $this->refused || $this->feedRequest !== null) {
            return;
        }
        if (! $this->loaded) {
            $checkpoint = $this->checkpoint();
            $this->cursor = $checkpoint['cursor'];
            $this->runs = $checkpoint['runs'];
            $this->loaded = true;
        }
        foreach (app(DirectOperation::class)->scheduledResultDefinitions() as $workflow => $_declaration) {
            if (isset($this->readable[$workflow]) || in_array($workflow, $this->readRequests, true)) {
                continue;
            }
            $id = bin2hex(random_bytes(16));
            $this->readRequests[$id] = $workflow;
            $this->socket->send(['type' => 'workflow.get', 'id' => $id, 'payload' => ['workflowIdOrName' => $workflow]]);
        }
        if (count($this->readable) !== count(app(DirectOperation::class)->scheduledResultDefinitions())) {
            return;
        }
        foreach (array_unique(array_column(app(DirectOperation::class)->scheduledResultDefinitions(), 'projection')) as $projection) {
            app($projection)->activate();
        }
        $this->feedRequest = bin2hex(random_bytes(16));
        $this->socket->send(['type' => 'commits.subscribe', 'id' => $this->feedRequest, 'payload' => ['cursor' => $this->cursor]]);
        // One startup/rejoin recovery of saved hints; no elapsed-time history retry.
        foreach ($this->runs as $run => $workflow) {
            $this->project($workflow, $run);
        }
    }

    public function close(): void
    {
        $this->socket->close();
        $this->feedRequest = null;
        $this->readRequests = [];
        $this->readable = [];
        $this->loaded = false;
    }

    /** @param array<string,mixed> $message */
    public function receive(array $message): bool
    {
        $id = $message['id'] ?? null;
        $workflow = is_string($id) ? ($this->readRequests[$id] ?? null) : null;
        if ($workflow !== null) {
            $payload = $message['payload'] ?? null;
            $definition = is_array($payload) ? ($payload['data'] ?? null) : null;
            $declared = app(DirectOperation::class)->scheduledResultDefinitions()[$workflow];
            if (($message['type'] ?? null) !== 'workflow.get' || ! is_array($definition)
                || ($definition['id'] ?? null) !== $workflow || ($definition['name'] ?? null) !== $declared['workflow']) {
                $this->refused = true;
                $this->log->error('The shared read credential cannot verify the declared scheduled workflow.', ['workflow_id' => $workflow]);
            } else {
                $this->readable[$workflow] = true;
                unset($this->readRequests[$id]);
                $this->subscribe();
            }

            return true;
        }
        if (is_string($id) && $id === $this->feedRequest) {
            $this->feed($message);

            return true;
        }

        return false;
    }

    /** @param array<string,mixed> $message */
    private function feed(array $message): void
    {
        if (($message['type'] ?? null) === 'error') {
            $error = is_array($message['error'] ?? null) ? $message['error'] : [];
            if (($error['code'] ?? null) === 'commits_lagged') {
                throw new WebSocketException('The native commit feed lagged; reconnect from the saved cursor.');
            }
            $this->refused = true;
            $this->log->error('Scheduled native discovery was refused; its saved cursor was preserved.');

            return;
        }
        $payload = $message['payload'] ?? null;
        if (($message['type'] ?? null) !== 'commits.subscribe' || ! is_array($payload)
            || ! is_int($payload['cursor'] ?? null) || $payload['cursor'] < $this->cursor
            || ! is_int($payload['head'] ?? null) || $payload['head'] < $payload['cursor']
            || ($payload['done'] ?? null) !== false || ! is_array($payload['changes'] ?? null) || ! array_is_list($payload['changes'])) {
            throw new WebSocketException('The native commit feed frame is invalid.');
        }
        $observed = [];
        $candidates = [];
        $previous = $this->cursor;
        $declarations = app(DirectOperation::class)->scheduledResultDefinitions();
        foreach ($payload['changes'] as $change) {
            if (! is_array($change) || ! is_int($change['cursor'] ?? null) || $change['cursor'] <= $previous
                || $change['cursor'] > $payload['cursor'] || ! is_array($change['commit'] ?? null)
                || ! is_array($change['commit']['ops'] ?? null)) {
                throw new WebSocketException('The native commit feed change is invalid.');
            }
            $previous = $change['cursor'];
            foreach ($change['commit']['ops'] as $op) {
                $intent = is_array($op) ? ($op['intent'] ?? null) : null;
                if (! is_array($intent) || ! in_array($intent['kind'] ?? null, ['run.create', 'run.update'], true)) {
                    continue;
                }
                $ref = $intent['run'] ?? null;
                if (! is_array($ref) || ! is_string($ref['workflowId'] ?? null)) {
                    continue;
                }
                if (! isset($declarations[$ref['workflowId']])) {
                    if (is_string($ref['runId'] ?? null) && Str::isUuid($ref['runId']) && Str::isUuid($ref['workflowId'])) {
                        $candidates[$ref['runId']] = $ref['workflowId'];
                    }

                    continue;
                }
                if (! is_string($ref['runId'] ?? null) || ! Str::isUuid($ref['runId'])) {
                    throw new WebSocketException('The native scheduled run reference is invalid.');
                }
                $observed[$ref['runId']] = $ref['workflowId'];
            }
        }
        // A Schedule's own workflow fires root runs; a run already hinted is followed after its Schedule is gone.
        if ($candidates !== []) {
            $fires = array_flip(array_filter(Schedule::query()->whereIn('swamp_workflow_id', array_unique($candidates))->pluck('swamp_workflow_id')->all(), is_string(...)));
            foreach ($candidates as $run => $workflow) {
                if (isset($fires[$workflow]) || ($this->runs[$run] ?? null) === self::Fire.$workflow) {
                    $observed[$run] = self::Fire.$workflow;
                }
            }
        }
        // Cursor and undispatched roots are one checkpoint, so restart cannot skip a discovered result.
        Cache::lock($this->key().':lock', 10)->block(2, function () use ($payload, $observed): void {
            $checkpoint = $this->checkpoint();
            $this->runs = [...$checkpoint['runs'], ...$observed];
            $this->cursor = max($checkpoint['cursor'], $payload['cursor']);
            $this->save($this->cursor, $this->runs);
        });
        foreach ($observed as $run => $workflow) {
            $this->project($workflow, $run);
        }
    }

    private function project(string $workflow, string $run): void
    {
        try {
            Bus::dispatch(str_starts_with($workflow, self::Fire)
                ? new ProjectSwampScheduleFire(substr($workflow, strlen(self::Fire)), $run, $this->key())
                : new ProjectSwampScheduledTerminal($workflow, $run, null, $this->key()));
        } catch (Throwable $exception) {
            $this->log->warning('The scheduled native result is unavailable.', ['workflow_id' => $workflow, 'run_id' => $run, 'exception_type' => $exception::class]);
        }
    }

    /** Acknowledge only after the queued shared projector accepted the exact native result. */
    public static function acknowledge(string $key, string $run): void
    {
        Cache::lock($key.':lock', 10)->block(2, function () use ($key, $run): void {
            $checkpoint = Cache::get($key);
            if (! is_array($checkpoint) || ! is_array($checkpoint['runs'] ?? null)) {
                return;
            }
            unset($checkpoint['runs'][$run]);
            if (! Cache::forever($key, $checkpoint)) {
                throw new WebSocketException('The scheduled discovery checkpoint could not be saved.');
            }
        });
    }

    /** @param array<string,string> $runs */
    private function save(int $cursor, array $runs): void
    {
        if (! Cache::forever($this->key(), ['cursor' => $cursor, 'runs' => $runs])) {
            throw new WebSocketException('The scheduled discovery checkpoint could not be saved.');
        }
    }

    /** @return array{cursor:int,runs:array<string,string>} */
    private function checkpoint(): array
    {
        $checkpoint = Cache::get($this->key(), ['cursor' => 0, 'runs' => []]);
        if (! is_array($checkpoint) || ! is_int($checkpoint['cursor'] ?? null) || $checkpoint['cursor'] < 0
            || ! is_array($checkpoint['runs'] ?? null)) {
            throw new WebSocketException('The scheduled discovery checkpoint is invalid.');
        }
        foreach ($checkpoint['runs'] as $run => $workflow) {
            if (! is_string($run) || ! Str::isUuid($run) || ! is_string($workflow)
                || (! isset(app(DirectOperation::class)->scheduledResultDefinitions()[$workflow])
                    && ! (str_starts_with($workflow, self::Fire) && Str::isUuid(substr($workflow, strlen(self::Fire)))))) {
                throw new WebSocketException('The scheduled discovery checkpoint is invalid.');
            }
        }

        return ['cursor' => $checkpoint['cursor'], 'runs' => $checkpoint['runs']];
    }

    private function key(): string
    {
        $ids = array_keys(app(DirectOperation::class)->scheduledResultDefinitions());
        sort($ids);

        return 'swamp:scheduled-results:'.hash('sha256', json_encode([config('swamp.address'), $ids], JSON_THROW_ON_ERROR));
    }
}
