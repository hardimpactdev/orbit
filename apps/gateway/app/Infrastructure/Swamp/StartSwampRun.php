<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\OperationBroadcaster;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One native submission. A lost confirmation never proves that no run started. */
final readonly class StartSwampRun
{
    /** @var Closure(): float */
    private Closure $clock;

    /** @param (Closure():float)|null $clock */
    public function __construct(private WebSocketClient $socket, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function start(Operation $operation): void
    {
        $started = microtime(true);
        $outcome = 'unavailable';
        $accepted = false;
        try {
            if (! $this->begin($operation)) {
                return;
            }
            $endpoint = $this->endpoint();
            $response = $this->request($endpoint, $this->payload($operation));
            $accepted = true;
            $this->confirm($operation, $response);
            $outcome = 'started';
        } catch (Throwable $exception) {
            $failure = $this->failStart($operation, $exception, $accepted);
            $outcome = $failure->errorCode;

            throw $failure;
        } finally {
            Log::info('Swamp direct start', ['operation_id' => $operation->id,
                'outcome' => $outcome, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
        }
    }

    /**
     * Starts several admitted runs as one batch: every `workflow.run` request is sent first, and each reply binds its run as it arrives
     * (docs/cli/proof/run.mmd). A run that fails or is uncertain ends only itself; the others go on. The first failure, in the order of
     * the runs, is thrown at the end, as `start()` throws its own. One run takes the path of `start()`.
     *
     * @param  list<Operation>  $operations
     */
    public function startMany(array $operations): void
    {
        if (count($operations) === 1) {
            $this->start($operations[0]);

            return;
        }
        $started = microtime(true);
        $payloads = [];
        $byId = [];
        $failures = [];
        $outcomes = [];
        $finish = static function (Operation $operation, string $outcome) use (&$outcomes, $started): void {
            $outcomes[$operation->id] = $outcome;
            Log::info('Swamp direct start', ['operation_id' => $operation->id, 'outcome' => $outcome, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
        };
        $fail = function (Operation $operation, Throwable $exception, bool $accepted) use (&$failures, $finish): void {
            $failure = $this->failStart($operation, $exception, $accepted);
            $failures[$operation->id] = $failure;
            $finish($operation, $failure->errorCode);
        };
        foreach ($operations as $operation) {
            try {
                if ($this->begin($operation)) {
                    $byId[$operation->id] = $operation;
                }
            } catch (Throwable $exception) {
                $fail($operation, $exception, false);
            }
        }
        $settled = function (int|string $id, SwampStartReply|ResourceOperationException $result) use ($byId, $fail, $finish): void {
            $operation = $byId[$id];
            try {
                if ($result instanceof ResourceOperationException) {
                    throw $result;
                }
                $this->confirm($operation, $result);
                $finish($operation, 'started');
            } catch (Throwable $exception) {
                $fail($operation, $exception, true);
            }
        };
        $endpoint = null;
        if ($byId !== []) {
            try {
                $endpoint = $this->endpoint();
            } catch (Throwable $exception) {
                foreach ($byId as $operation) {
                    $fail($operation, $exception, false);
                }
            }
        }
        foreach ($endpoint === null ? [] : $byId as $id => $operation) {
            try {
                $payloads[$id] = $this->payload($operation);
            } catch (Throwable $exception) {
                $fail($operation, $exception, false);
            }
        }
        if ($endpoint !== null && $payloads !== []) {
            try {
                $this->requestMany($endpoint, $payloads, $settled);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        // A run without an outcome was sent, or its outcome could not be recorded, so its Swamp run may exist.
        foreach ($byId as $id => $operation) {
            if (! isset($outcomes[$id])) {
                $fail($operation, $this->uncertain(), true);
            }
        }
        Log::info('Swamp direct start batch', ['count' => count($operations), 'sent' => count($payloads),
            'started' => count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'started')),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
        foreach ($operations as $operation) {
            if (isset($failures[$operation->id])) {
                throw $failures[$operation->id];
            }
        }
    }

    /** @return array<string,mixed> the `workflow.run` payload of an admitted run */
    private function payload(Operation $operation): array
    {
        $declaration = app(DirectOperation::class)->definition($operation->kind);
        if ($declaration === null) {
            throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
        }

        return ['workflowIdOrName' => $declaration['workflow'],
            'inputs' => app(DirectOperation::class)->workflowInputs($operation),
            'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id]];
    }

    private function confirm(Operation $operation, SwampStartReply $response): void
    {
        $this->bind($operation, $response->runId);
        if ($response->failed) {
            throw new ResourceOperationException('operation.native_dispatch_failed', 'The native workflow ended before its start completed.', 503);
        }
        if (app(DirectOperation::class)->usesNativeTerminal($operation)) {
            app(OperationBroadcaster::class)->changed($operation, true);
        }
    }

    /** Ends the run that failed to start and returns the error that `start()` throws for it. */
    private function failStart(Operation $operation, Throwable $exception, bool $accepted): ResourceOperationException
    {
        $safe = $exception instanceof ResourceOperationException
            ? $exception : ($accepted ? $this->uncertain()
                : new ResourceOperationException('swamp.start_unavailable', 'The native workflow start is unavailable.', 503));
        $this->fail($operation, $safe->errorCode);

        return new ResourceOperationException($safe->errorCode, $safe->getMessage(), $safe->status, details: ['operation_id' => $operation->id]);
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

    /** Fails an admitted Operation whose run never started, such as after a failed execution copy. */
    public function refuse(Operation $operation, string $code): void
    {
        Log::info('Swamp direct start', ['operation_id' => $operation->id, 'outcome' => $code, 'duration_ms' => 0]);
        $this->fail($operation, $code);
    }

    private function fail(Operation $operation, string $code): void
    {
        $uncertain = DB::transaction(function () use ($operation, $code): bool {
            $this->lock($operation);
            if ($operation->status->terminal()) {
                return false;
            }
            if ($code === 'swamp.start_uncertain') {
                app(OperationStore::class)->change($operation, ['error_code' => $code]);

                return true;
            }
            app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(),
                'error_code' => $code, 'current_step' => null, 'failed_step' => null, 'lock_key' => null]);
            if (! isset($operation->state['swamp_run_id']) && DirectOperation::owns($operation)) {
                $projection = app(DirectOperation::class)->projection($operation);
                if ($projection instanceof DirectOperationStartRefusal) {
                    $projection->refused($operation);
                }
            }

            return false;
        });
        if ($uncertain && app(DirectOperation::class)->usesNativeTerminal($operation)) {
            DB::afterCommit(fn () => app(OperationBroadcaster::class)->changed($operation, true));
        }
    }

    private function lock(Operation $operation): void
    {
        $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function request(WebSocketEndpoint $endpoint, array $payload): SwampStartReply
    {
        $result = $this->requestMany($endpoint, [0 => $payload])[0];
        if ($result instanceof ResourceOperationException) {
            throw $result;
        }

        return $result;
    }

    /**
     * @param  array<int|string, array<string,mixed>>  $payloads
     * @param  (Closure(int|string, SwampStartReply|ResourceOperationException): void)|null  $settled  called for each run when its outcome is known
     * @return array<int|string, SwampStartReply|ResourceOperationException>
     */
    private function requestMany(WebSocketEndpoint $endpoint, array $payloads, ?Closure $settled = null): array
    {
        $dispatch = app(SwampDispatch::class);
        $shared = false;
        $socket = $this->socket;
        try {
            [$socket, $shared] = $dispatch->socket($endpoint, 15, $this->socket);
        } catch (Throwable $exception) {
            Log::warning('Swamp direct start response unavailable', ['workflow' => reset($payloads)['workflowIdOrName'] ?? null, 'stage' => 'connect',
                'confirmation_seconds' => SwampStartBatch::ConfirmationSeconds, 'exception_type' => $exception::class]);
            $refusal = $exception instanceof ResourceOperationException
                ? $exception : new ResourceOperationException('swamp.start_unavailable', 'The native workflow start could not connect.', 503);

            $results = array_map(static fn (): ResourceOperationException => $refusal, $payloads);
            foreach ($results as $key => $result) {
                if ($settled !== null) {
                    $settled($key, $result);
                }
            }

            return $results;
        }
        try {
            $results = (new SwampStartBatch($this->clock))->run($socket, $endpoint, $payloads, $settled);
            // A refused or unanswered request drops the shared connection; the next one opens a new one.
            if ($shared && array_any($results, static fn (mixed $result): bool => $result instanceof ResourceOperationException)) {
                $dispatch->release();
            }

            return $results;
        } catch (Throwable $exception) {
            if ($shared) {
                $dispatch->release();
            }

            throw $exception;
        } finally {
            if (! $shared) {
                $socket->close();
            }
        }
    }

    private function uncertain(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.start_uncertain', 'Swamp did not confirm its start. A native run may exist; inspect Swamp before another admission.', 503);
    }
}
