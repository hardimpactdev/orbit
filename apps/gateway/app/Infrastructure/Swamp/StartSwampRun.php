<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Instances\InstanceDestroyEngineFlow;
use App\Domain\Instances\Power\ExecutorFence;
use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\NativeRunCancellation;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** One native submission. A lost confirmation never proves that no run started. */
final readonly class StartSwampRun
{
    private const int ConfirmationSeconds = 120;

    /** The events serve sends for a start before it saves a run. */
    private const array PreRunEvents = ['validating_inputs', 'superseded_runs', 'evaluating_workflow'];

    /**
     * A start that serve did not take is queued again until this many seconds have passed since its first deferral: a connection
     * that failed (serve down, or its 50 handshakes a minute from one address used up) or an admission refusal (`not_ready` while
     * serve boots, `draining` while it stops). It covers a serve restart: up to 6 minutes of stop (`TimeoutStopSec`) and its boot.
     * Each attempt restarts the 600-second admission clock, so the start ends with its own code, never `operation.timed_out`.
     */
    public const int DeferralBudgetSeconds = 420;

    /** The refusals that serve's admission gate sends before it saves a run: serve booting, or serve stopping. */
    private const array NotAdmitted = ['not_ready', 'draining'];

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
        if (app(DirectOperation::class)->definition($operation) === null) {
            return;
        }
        $jobStarted ??= microtime(true);
        $started = ($this->clock)();
        $outcome = 'unavailable';
        $accepted = false;
        $submitted = false;
        $phases = [];
        $connectFailure = null;
        $notAdmitted = null;
        try {
            if (! $this->begin($operation)) {
                return;
            }
            // A kind on the Gateway state machine starts there, not on Swamp (ADR 0271 N4).
            if (app(EngineKinds::class)->routes($operation)) {
                $runId = EngineRun::runIdOf($operation->id);
                if ($operation->kind === InstanceDestroyEngineFlow::Kind) {
                    $lock = OperationStore::lockKey('instance', $operation->target_id);
                    try {
                        app(Engine::class)->start($operation, fn () => $this->bind($operation, $runId, $lock));
                    } catch (UniqueConstraintViolationException $exception) {
                        throw new ResourceOperationException('operation.target_busy', 'Another Operation owns the Instance lock.', 409, previous: $exception);
                    }
                } else {
                    $this->bind($operation, $runId);
                    app(Engine::class)->start($operation->refresh());
                }
                $outcome = 'started';

                return;
            }
            $endpoint = $this->endpoint();
            // The run-time execution copies and the start share one connection: one serve handshake per start.
            $response = app(SwampDispatch::class)->shared(function () use ($operation, $endpoint, $started, &$submitted, &$phases, &$connectFailure, &$notAdmitted): array {
                $payload = [
                    'workflowIdOrName' => app(DirectOperation::class)->workflowFor($operation),
                    'inputs' => app(DirectOperation::class)->workflowInputs($operation),
                    'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id],
                ];
                $phases['inputs_ms'] = $this->milliseconds($started);

                return $this->request($endpoint, $payload, $submitted, $phases, $connectFailure, $notAdmitted);
            });
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
            // A start serve did not admit (`not_ready`, `draining`) ran no run either: it waits as a failed connection does.
            if (($connectFailure !== null || $notAdmitted !== null) && ! $submitted
                && $this->defer($operation, self::deferralSeconds($operation, $connectFailure, $notAdmitted))) {
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

    /** Submit an on-demand policy run. Policy owns durable admission keys; a later tick can observe again safely.
     * @param  array<string, mixed>  $inputs
     */
    public function submit(string $workflow, array $inputs): string
    {
        $submitted = false;
        $phases = [];

        return $this->request($this->endpoint(), ['workflowIdOrName' => $workflow, 'inputs' => $inputs], $submitted, $phases)['run_id'];
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
            if ($operation->cancel_requested_at !== null && ! ExecutorFence::issued($operation)) {
                app(OperationStore::class)->change($operation, ['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'error_code' => null, 'lock_key' => null]);

                return false;
            }
            app(OperationStore::class)->change($operation, ['status' => OperationStatus::Running, 'started_at' => now(), 'error_code' => null, 'cancel_requested_at' => null]);

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

    private function bind(Operation $operation, string $runId, ?string $lockKey = null): void
    {
        DB::transaction(function () use ($operation, $runId, $lockKey): void {
            $this->lock($operation);
            if (isset($operation->state['swamp_run_id'])) {
                app(DirectOperation::class)->assertRun($operation, $runId);
            }
            app(OperationStore::class)->change($operation, ['dispatched_at' => now(),
                'state' => [...($operation->state ?? []), 'swamp_run_id' => $runId], ...($lockKey === null ? [] : ['lock_key' => $lockKey])]);
            // A cancel that arrived while the start was in flight goes on with the run's id, through the one forward rule.
            if ($operation->cancel_requested_at !== null) {
                app(NativeRunCancellation::class)->request($operation);
            }
        });
    }

    /**
     * Queues a start again whose connection failed, or that serve's admission gate refused. Nothing was sent on a failed
     * connection, and `workflow.run` is the first frame of a start; a refusal of the gate comes before serve saves a run. So no
     * run can exist. The Operation returns to `queued`; its new job begins it again under the same lock, so at most one job ever
     * submits. A proof child keeps its own serialized retry. Once the budget has passed since the first deferral, the start
     * fails as before: `swamp.start_unavailable` (connection), `swamp.vault_sync_unavailable` (copy) or `swamp.start_refused`.
     */
    private function defer(Operation $operation, int $seconds): bool
    {
        if (isset($operation->state['proof_child_start'])) {
            return false;
        }
        $deferred = DB::transaction(function () use ($operation): bool {
            $this->lock($operation);
            $count = is_int($operation->state['start_deferrals'] ?? null) ? $operation->state['start_deferrals'] : 0;
            $since = is_int($operation->state['start_deferred_since'] ?? null) ? $operation->state['start_deferred_since'] : now()->getTimestamp();
            if ($operation->status !== OperationStatus::Running || isset($operation->state['swamp_run_id']) || $operation->dispatched_at !== null
                || $operation->cancel_requested_at !== null || now()->getTimestamp() - $since >= self::DeferralBudgetSeconds) {
                return false;
            }
            // started_at stays: the admission budget keeps counting from this start, and the next begin records a new one.
            app(OperationStore::class)->change($operation, ['status' => OperationStatus::Queued, 'error_code' => null,
                'state' => [...($operation->state ?? []), 'start_deferrals' => $count + 1, 'start_deferred_since' => $since]]);

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

    /**
     * The wait before a deferred start runs again: a doubling backoff of 5, 10, 20, 40 seconds, never shorter than serve's
     * `Retry-After` plus one second and never longer than 65 seconds. Serve asks a refused start back after 2 or 5 seconds; the
     * backoff keeps a long drain from spending a handshake every few seconds per waiting start.
     */
    private static function deferralSeconds(Operation $operation, ?Throwable $exception, ?int $notAdmitted): int
    {
        $count = is_int($operation->state['start_deferrals'] ?? null) ? $operation->state['start_deferrals'] : 0;
        $retryAfter = $exception instanceof WebSocketException ? $exception->retryAfterSeconds : $notAdmitted;

        return min(self::MaxDeferralSeconds, max(5 << min($count, 4), ($retryAfter ?? 0) + 1));
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
    private function request(WebSocketEndpoint $endpoint, array $payload, bool &$submitted, array &$phases, ?Throwable &$connectFailure = null, ?int &$notAdmitted = null): array
    {
        $id = bin2hex(random_bytes(16));
        $stage = 'connect';
        $started = ($this->clock)();
        $dispatch = app(SwampDispatch::class);
        $shared = false;
        $socket = $this->socket;
        try {
            $connecting = ($this->clock)();
            [$socket, $shared] = $dispatch->socket($endpoint, 15, $this->socket, SwampCaller::Start);
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
            // Serve saves a run right before it yields `started`, and a `started` it cannot send is dropped unseen: once any event
            // but a pre-run one arrived, a later error event may follow a run that exists.
            $runMayExist = false;
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
                    // Serve rejects a start whose inputs fail validation or resolution with a root error event that names no run,
                    // after only pre-run events, then `done`; a protocol failure comes as a `type: error` message.
                    $refusal = match (true) {
                        ($message['type'] ?? null) === 'error' => ['error', is_array($message['error'] ?? null) ? $message['error'] : $message],
                        ($message['type'] ?? null) === 'event' && is_array($event) && ($event['kind'] ?? null) === 'error' && ! $runMayExist
                            && ! isset($event['runId']) && ! isset($event['parentRunId']) => ['event:error', is_array($event['error'] ?? null) ? $event['error'] : $event],
                        default => null,
                    };
                    if (($message['type'] ?? null) === 'event' && ! in_array(is_array($event) ? ($event['kind'] ?? null) : null, self::PreRunEvents, true)) {
                        $runMayExist = true;
                    }
                    if ($refusal !== null && $confirmed === null) {
                        [$remoteType, $error] = $refusal;
                        $redact = static function (mixed $value, int $limit) use ($endpoint): string {
                            if (! is_string($value) || strlen($value) > 32768 || preg_match('//u', $value) !== 1) {
                                return '[withheld]';
                            }

                            $safe = app(LogRedactor::class)->redact($value, array_filter([$endpoint->bearerToken]));

                            return Str::limit(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $safe) ?? '[withheld]', $limit);
                        };
                        Log::warning('Swamp direct start refused', [
                            'workflow' => $payload['workflowIdOrName'], 'remote_type' => $remoteType,
                            'remote_code' => $redact($error['code'] ?? null, 100),
                            'remote_message' => $redact($error['message'] ?? null, 2000),
                        ]);
                        // A refusal after only pre-run events, none of which confirms a run, proves that no run was accepted.
                        $submitted = false;
                        // Only before any event that may follow a saved run: a resubmit must never follow a possible run.
                        if ($remoteType === 'error' && ! $runMayExist && in_array($error['code'] ?? null, self::NotAdmitted, true)) {
                            $notAdmitted = is_int($error['retryAfterSeconds'] ?? null) ? max(0, $error['retryAfterSeconds']) : 0;
                        }
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
