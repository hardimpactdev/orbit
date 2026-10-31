<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketException;
use App\Jobs\Operations\ProjectSwampScheduledTerminal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/** Official commit-feed discovery beside bound-root attachments on the shared observer socket. */
final class SwampScheduledResults
{
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

    /** @var array<string,array{run:string,attached:bool}> */
    private array $requests = [];

    public function __construct(private readonly WebSocketClient $socket, private readonly LoggerInterface $log) {}

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
            $checkpoint = Cache::get($this->key(), ['cursor' => 0, 'runs' => []]);
            if (! is_array($checkpoint) || ! is_int($checkpoint['cursor'] ?? null) || $checkpoint['cursor'] < 0
                || ! is_array($checkpoint['runs'] ?? null)) {
                throw new WebSocketException('The scheduled discovery checkpoint is invalid.');
            }
            foreach ($checkpoint['runs'] as $run => $workflow) {
                if (! is_string($run) || ! Str::isUuid($run) || ! is_string($workflow)
                    || ! isset(app(DirectOperation::class)->scheduledResultDefinitions()[$workflow])) {
                    throw new WebSocketException('The scheduled discovery checkpoint is invalid.');
                }
            }
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
        $this->feedRequest = bin2hex(random_bytes(16));
        $this->socket->send(['type' => 'commits.subscribe', 'id' => $this->feedRequest, 'payload' => ['cursor' => $this->cursor]]);
        $this->attach();
    }

    public function attach(): void
    {
        if ($this->feedRequest === null || $this->refused) {
            return;
        }
        foreach ($this->runs as $run => $_workflow) {
            if (in_array($run, array_column($this->requests, 'run'), true)) {
                continue;
            }
            $id = bin2hex(random_bytes(16));
            $this->requests[$id] = ['run' => $run, 'attached' => false];
            $this->socket->send(['type' => 'run.attach', 'id' => $id, 'payload' => ['runId' => $run, 'afterSeq' => 0]]);
        }
    }

    public function disconnected(): void
    {
        $this->feedRequest = null;
        $this->requests = [];
        $this->readRequests = [];
        $this->readable = [];
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
        $entry = is_string($id) ? ($this->requests[$id] ?? null) : null;
        if ($entry === null) {
            return false;
        }
        $run = $entry['run'];
        $workflow = $this->runs[$run];
        $type = $message['type'] ?? null;
        $payload = $message['payload'] ?? null;
        $error = is_array($message['error'] ?? null) ? $message['error'] : [];
        if ($type === 'run.attached' && is_array($payload) && ($payload['runId'] ?? null) === $run
            && in_array($payload['kind'] ?? null, ['workflow-run', 'workflow-resume'], true)) {
            $this->requests[$id]['attached'] = true;
        } elseif (($type === 'run.interrupted' && is_array($payload) && ($payload['runId'] ?? null) === $run)
            || ($type === 'error' && ($error['code'] ?? null) === 'not_found')) {
            $this->project($workflow, $run, null, $id);
        } elseif ($type === 'event' && $entry['attached']) {
            $event = $message['event'] ?? null;
            $native = is_array($event) ? ($event['run'] ?? null) : null;
            $declaration = app(DirectOperation::class)->scheduledResultDefinitions()[$workflow];
            if (is_array($event) && ! isset($event['parentRunId']) && is_int($event['seq'] ?? null) && $event['seq'] > 0
                && in_array($event['kind'] ?? null, ['completed', 'cancelled'], true) && is_array($native)
                && ($native['id'] ?? null) === $run && ($native['workflowName'] ?? null) === $declaration['workflow']
                && in_array($native['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
                $this->project($workflow, $run, $native['status'], $id);
            }
        } elseif ($type === 'error' || $type === 'run.elsewhere') {
            $this->log->warning('The scheduled native root attachment was refused.', ['workflow_id' => $workflow, 'run_id' => $run]);
        }

        return true;
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
        $runs = $this->runs;
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
                if (! is_array($ref) || ! is_string($ref['workflowId'] ?? null) || ! isset($declarations[$ref['workflowId']])) {
                    continue;
                }
                if (! is_string($ref['runId'] ?? null) || ! Str::isUuid($ref['runId'])) {
                    throw new WebSocketException('The native scheduled run reference is invalid.');
                }
                $runs[$ref['runId']] = $ref['workflowId'];
            }
        }
        // Cursor and undispatched roots are one checkpoint, so restart cannot skip a discovered result.
        $this->save($payload['cursor'], $runs);
        $this->cursor = $payload['cursor'];
        $this->runs = $runs;
    }

    private function project(string $workflow, string $run, ?string $status, string $request): void
    {
        try {
            ProjectSwampScheduledTerminal::dispatchSync($workflow, $run, $status);
            $remaining = $this->runs;
            unset($remaining[$run]);
            $this->save($this->cursor, $remaining);
            $this->runs = $remaining;
            unset($this->requests[$request]);
        } catch (Throwable $exception) {
            $this->log->warning('The scheduled native result is unavailable.', ['workflow_id' => $workflow, 'run_id' => $run, 'exception_type' => $exception::class]);
        }
    }

    /** @param array<string,string> $runs */
    private function save(int $cursor, array $runs): void
    {
        if (! Cache::forever($this->key(), ['cursor' => $cursor, 'runs' => $runs])) {
            throw new WebSocketException('The scheduled discovery checkpoint could not be saved.');
        }
    }

    private function key(): string
    {
        $ids = array_keys(app(DirectOperation::class)->scheduledResultDefinitions());
        sort($ids);

        return 'swamp:scheduled-results:'.hash('sha256', json_encode([config('swamp.address'), $ids], JSON_THROW_ON_ERROR));
    }
}
