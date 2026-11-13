<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\OperationBroadcaster;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** One native submission. A lost confirmation never proves that no run started. */
final readonly class StartSwampRun
{
    private const int ConfirmationSeconds = 120;

    /**
     * A start whose connection failed is queued again this many times. Serve admits 50 connections from one address in a fixed
     * minute and answers a refused one with the rest of that minute as `Retry-After`; three deferrals cover more than a minute.
     */
    public const int MaxDeferrals = 3;

    /** The longest wait before a deferred start runs again: serve's window plus a margin. */
    public const int MaxDeferralSeconds = 65;

    /** @var Closure(): float */
    private Closure $clock;

    /** @param (Closure():float)|null $clock */
    public function __construct(private WebSocketClient $socket, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public function start(Operation $operation, ?float $jobStarted = null): void
    {
        $jobStarted ??= microtime(true);
        $started = ($this->clock)();
        $outcome = 'unavailable';
        $accepted = false;
        $submitted = false;
        $phases = [];
        $connectFailure = null;
        try {
            if (! $this->begin($operation)) {
                return;
            }
            $declaration = app(DirectOperation::class)->definition($operation->kind);
            if ($declaration === null) {
                throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
            }
            $endpoint = $this->endpoint();
            $payload = [
                'workflowIdOrName' => $declaration['workflow'],
                'inputs' => app(DirectOperation::class)->workflowInputs($operation),
                'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id],
            ];
            $phases['inputs_ms'] = $this->milliseconds($started);
            $response = $this->request($endpoint, $payload, $submitted, $phases, $connectFailure);
            $accepted = true;
            $binding = ($this->clock)();
            $this->bind($operation, $response['run_id']);
            $phases['bind_ms'] = $this->milliseconds($binding);
            if ($response['failed']) {
                throw new ResourceOperationException('operation.native_dispatch_failed', 'The native workflow ended before its start completed.', 503);
            }
            $outcome = 'started';
            $broadcasting = ($this->clock)();
            if (app(DirectOperation::class)->usesNativeTerminal($operation)) {
                app(OperationBroadcaster::class)->changed($operation, true);
            }
            $phases['broadcast_ms'] = $this->milliseconds($broadcasting);
        } catch (Throwable $exception) {
            // A run-time execution copy whose connect failed also ran before any start frame.
            $connectFailure ??= $exception instanceof ResourceOperationException && $exception->errorCode === 'swamp.vault_sync_unavailable'
                && $exception->getPrevious() instanceof WebSocketException ? $exception->getPrevious() : null;
            if ($connectFailure !== null && ! $submitted && $this->defer($operation, self::deferralSeconds($operation, $connectFailure))) {
                $outcome = 'deferred';

                return;
            }
            $safe = $exception instanceof ResourceOperationException
                ? $exception : ($accepted ? $this->uncertain()
                    : new ResourceOperationException('swamp.start_unavailable', 'The native workflow start is unavailable.', 503));
            $outcome = $safe->errorCode;
            $this->fail($operation, $safe, notSubmitted: ! $submitted);
            throw new ResourceOperationException($safe->errorCode, $safe->getMessage(), $safe->status, details: ['operation_id' => $operation->id]);
        } finally {
            if ($outcome === 'started') {
                try {
                    Log::info('Swamp direct start', ['operation_id' => $operation->id,
                        'outcome' => $outcome, 'duration_ms' => (int) round((($this->clock)() - $started) * 1000),
                        'kind' => $operation->kind, ...$phases,
                        'queue_wait_ms' => self::queueWaitMilliseconds($operation, $jobStarted)]);
                } catch (Throwable) {
                    // A diagnostic failure must not fail an already bound start.
                }
            } else {
                Log::info('Swamp direct start', ['operation_id' => $operation->id,
                    'outcome' => $outcome, 'duration_ms' => (int) round((($this->clock)() - $started) * 1000)]);
            }
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

    /**
     * Queues a start again whose connection failed. Nothing was sent on that connection, and `workflow.run` is the first
     * frame of a start, so no run can exist. The Operation returns to `queued`; its new job begins it again under the same
     * lock, so at most one job ever submits. A proof child keeps its own serialized retry; the last deferral fails as before.
     */
    private function defer(Operation $operation, int $seconds): bool
    {
        if (isset($operation->state['proof_child_start'])) {
            return false;
        }
        $deferred = DB::transaction(function () use ($operation): bool {
            $this->lock($operation);
            $count = is_int($operation->state['start_deferrals'] ?? null) ? $operation->state['start_deferrals'] : 0;
            if ($operation->status !== OperationStatus::Running || isset($operation->state['swamp_run_id']) || $operation->dispatched_at !== null
                || $operation->cancel_requested_at !== null || $count >= self::MaxDeferrals) {
                return false;
            }
            // started_at stays: the admission budget keeps counting from this start, and the next begin records a new one.
            app(OperationStore::class)->change($operation, ['status' => OperationStatus::Queued, 'error_code' => null,
                'state' => [...($operation->state ?? []), 'start_deferrals' => $count + 1]]);

            return true;
        });
        if (! $deferred) {
            return false;
        }
        Log::info('Swamp direct start deferred', ['operation_id' => $operation->id, 'delay_seconds' => $seconds,
            'deferral' => $operation->state['start_deferrals'] ?? null]);
        try {
            Bus::dispatch(new StartAdmittedSwampRun($operation->id)->delay(now()->addSeconds($seconds)));
        } catch (Throwable $exception) {
            Log::warning('Swamp start could not be queued', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
            // Only while it is still queued: a duplicate job may have begun it since.
            $this->refuse($operation, new ResourceOperationException('swamp.start_enqueue_failed', 'The native workflow start could not be queued.', 503), onlyQueued: true);
        }

        return true;
    }

    /** The wait before a start whose connection failed runs again: serve's `Retry-After` when it named one, else a doubling backoff. */
    private static function deferralSeconds(Operation $operation, Throwable $exception): int
    {
        $count = is_int($operation->state['start_deferrals'] ?? null) ? $operation->state['start_deferrals'] : 0;
        $retryAfter = $exception instanceof WebSocketException ? $exception->retryAfterSeconds : null;

        return min(self::MaxDeferralSeconds, max(1, $retryAfter === null ? 5 << $count : $retryAfter + 1));
    }

    /** Fails an admitted Operation whose run never started, such as after a failed execution copy. */
    public function refuse(Operation $operation, ResourceOperationException $error, bool $onlyQueued = false): void
    {
        Log::info('Swamp direct start', ['operation_id' => $operation->id, 'outcome' => $error->errorCode, 'duration_ms' => 0]);
        $this->fail($operation, $error, notSubmitted: true, onlyQueued: $onlyQueued);
    }

    private function fail(Operation $operation, ResourceOperationException $error, bool $notSubmitted, bool $onlyQueued = false): void
    {
        $code = $error->errorCode;
        $uncertain = DB::transaction(function () use ($operation, $code, $error, $notSubmitted, $onlyQueued): bool {
            $this->lock($operation);
            if ($operation->status->terminal() || ($onlyQueued && ($operation->status !== OperationStatus::Queued || isset($operation->state['swamp_run_id'])))) {
                return false;
            }
            if ($code === 'swamp.start_uncertain') {
                app(OperationStore::class)->change($operation, ['error_code' => $code]);

                return true;
            }
            // A start that the Gateway refused keeps why: the run never existed, so nothing else records it. The failure detail is what
            // every Operation read shows as its error.
            app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(),
                'error_code' => $code, 'current_step' => null, 'failed_step' => null, 'lock_key' => null,
                'state' => [...($operation->state ?? []),
                    ...($operation->kind === InstanceMigration::Kind && $notSubmitted && ! isset($operation->state['swamp_run_id'])
                        ? ['native_start_not_submitted' => true] : []),
                    'native_failure' => ['failed_step' => null, 'detail' => $error->getMessage(), 'stderr_recorded' => false]],
                'result' => ['error' => ['code' => $code, 'message' => $error->getMessage(), 'details' => $error->details]]]);
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

    /** @param array<string,mixed> $payload
     * @param  array<string,int>  $phases
     * @return array{run_id:string,failed:bool}
     */
    private function request(WebSocketEndpoint $endpoint, array $payload, bool &$submitted, array &$phases, ?Throwable &$connectFailure = null): array
    {
        $id = bin2hex(random_bytes(16));
        $stage = 'connect';
        $started = ($this->clock)();
        $dispatch = app(SwampDispatch::class);
        $shared = false;
        $socket = $this->socket;
        try {
            $connecting = ($this->clock)();
            [$socket, $shared] = $dispatch->socket($endpoint, 15, $this->socket);
            $phases['connect_ms'] = $this->milliseconds($connecting);
            $stage = 'send';
            $sending = ($this->clock)();
            // Once sending begins, failure can no longer disprove submission.
            $submitted = true;
            $socket->send(['type' => 'workflow.run', 'id' => $id, 'payload' => $payload]);
            $phases['send_ms'] = $this->milliseconds($sending);
            $stage = 'receive';
            $receiving = ($this->clock)();
            // Observed pre-dispatch setup took 32.7s and 55.3s; worker dispatch follows started.
            $deadline = ($this->clock)() + self::ConfirmationSeconds;
            while ($socket->isConnected() && ($remaining = $deadline - ($this->clock)()) > 0) {
                $confirmed = null;
                foreach ($socket->receive(min(0.2, $remaining)) as $message) {
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
                        // An explicit refusal before any root event proves that no run was accepted.
                        $submitted = false;
                        throw new ResourceOperationException('swamp.start_refused', 'Swamp refused the workflow start. Inspect Swamp before another admission.', 503);
                    }
                }
                if ($confirmed !== null) {
                    $phases['first_event_ms'] = $this->milliseconds($receiving);

                    return $confirmed;
                }
            }
            throw $this->uncertain();
        } catch (Throwable $exception) {
            if ($shared) {
                $dispatch->release();
            }
            $inputs = $payload['inputs'] ?? null;
            Log::warning('Swamp direct start response unavailable', ['operation_id' => is_array($inputs) ? ($inputs['operation_id'] ?? null) : null,
                'workflow' => $payload['workflowIdOrName'], 'request_id' => $id, 'stage' => $stage,
                'elapsed_ms' => (int) round((($this->clock)() - $started) * 1000),
                'confirmation_seconds' => self::ConfirmationSeconds, 'exception_type' => $exception::class]);
            if ($exception instanceof ResourceOperationException) {
                throw $exception;
            }
            if ($stage === 'connect') {
                // The connection failed before any frame was sent: the start may be queued again.
                $connectFailure = $exception;
            }
            throw $stage === 'connect'
                ? new ResourceOperationException('swamp.start_unavailable', 'The native workflow start could not connect.', 503)
                : $this->uncertain();
        } finally {
            if (! $shared) {
                $socket->close();
            }
        }
    }

    private function milliseconds(float $started): int
    {
        return max(0, (int) floor((($this->clock)() - $started) * 1000));
    }

    private static function queueWaitMilliseconds(Operation $operation, float $jobStarted): int
    {
        $created = $operation->created_at;

        return $created === null ? 0 : max(0, (int) floor(($jobStarted - (float) $created->format('U.u')) * 1000));
    }

    private function uncertain(): ResourceOperationException
    {
        return new ResourceOperationException('swamp.start_uncertain', 'Swamp did not confirm its start. A native run may exist; inspect Swamp before another admission.', 503);
    }
}
