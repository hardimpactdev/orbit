<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Proof\ProofChildStarts;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AgentView\WebSocketException;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Operation;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Swamp network work that an admission needs runs after its transaction commits, never under the SQLite write lock.
 * Execution copies push first. Admitted starts are queued only when every copy is stored; a failed copy fails them unstarted.
 * Outside a transaction copies push and starts queue at once. A rolled-back transaction discards its work.
 *
 * One dispatch shares one WebSocket for its copies, because serve admits 50 handshakes from one address in a fixed minute
 * and matches each reply to its request by `id`. A start runs its run-time copies and its `workflow.run` inside `shared()`, so
 * one start costs one handshake. A request that fails drops the shared connection; the next one opens a new one.
 *
 * An admission copy that serve refuses with 429 waits out `Retry-After` once and is pushed again, in a console process only (a
 * queue job or a scheduled command): no frame was sent, and an admission has no start job that a deferral could queue again.
 */
#[Scoped]
final class SwampDispatch
{
    /** @var array<int, Closure(): void> */
    private array $copies = [];

    /** @var array<int, Operation> */
    private array $starts = [];

    private int $next = 0;

    /** The longest `Retry-After` an admission copy waits out: serve's window of 60 s plus a margin. */
    public const int MaxAdmissionWaitSeconds = 61;

    /** How many sharing scopes are open: a dispatch, or a start's `shared()`. The last one to close drops the connection. */
    private int $depth = 0;

    /** @param Closure(): void $push */
    public function copy(Closure $push): void
    {
        $id = $this->next++;
        $this->copies[$id] = $push;
        DB::afterRollBack(function () use ($id): void {
            unset($this->copies[$id]);
        });
        DB::afterCommit(fn () => $this->dispatch());
    }

    public function start(Operation $operation): void
    {
        $id = $this->next++;
        $this->starts[$id] = $operation;
        DB::afterRollBack(function () use ($id): void {
            unset($this->starts[$id]);
        });
        DB::afterCommit(fn () => $this->dispatch());
    }

    /** A recovery push may duplicate an existing start. Its failure cannot end that Operation. */
    public function retry(Operation $operation): void
    {
        DB::afterCommit(static function () use ($operation): void {
            try {
                Bus::dispatch(new StartAdmittedSwampRun($operation->id));
            } catch (Throwable $exception) {
                Log::warning('Swamp start retry could not be queued', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                throw new ResourceOperationException('swamp.start_enqueue_failed', 'The native workflow start retry could not be queued.', 503);
            }
        });
    }

    /**
     * A connected socket to `$endpoint` and whether it is the dispatch's shared one, which the caller must not close.
     * Outside a sharing scope it connects `$own`.
     *
     * @return array{0: WebSocketClient, 1: bool}
     */
    public function socket(WebSocketEndpoint $endpoint, float $timeoutSeconds, WebSocketClient $own, SwampCaller $caller): array
    {
        if ($this->depth === 0) {
            app(SwampHandshakes::class)->connect($own, $endpoint, $timeoutSeconds, $caller);

            return [$own, false];
        }

        return [app(SwampConnection::class)->socket($endpoint, $timeoutSeconds, $own, $caller), true];
    }

    /** Closes the shared connection, after a request on it failed or when the dispatch ends. */
    public function release(): void
    {
        app(SwampConnection::class)->release();
    }

    /**
     * Runs `$work` on one connection: every copy and request inside it shares the socket, which closes when it ends. A start uses
     * it for its run-time copies and its `workflow.run`; serve then streams the run's events on that socket, so it is never kept.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function shared(Closure $work): mixed
    {
        $this->depth++;
        try {
            return $work();
        } finally {
            $this->close();
        }
    }

    private function close(): void
    {
        if (--$this->depth === 0) {
            $this->release();
        }
    }

    private function dispatch(): void
    {
        [$copies, $starts] = [$this->copies, $this->starts];
        $this->copies = $this->starts = [];
        $admission = $this->depth === 0;
        $this->depth++;
        try {
            $waited = false;
            while (($push = reset($copies)) !== false) {
                try {
                    $push();
                    array_shift($copies);
                } catch (ResourceOperationException $error) {
                    $wait = $admission && ! $waited ? self::admissionWait($error) : null;
                    if ($wait !== null) {
                        $waited = true;
                        Log::info('Swamp execution copy waits for serve', ['delay_seconds' => $wait]);
                        Sleep::for($wait)->seconds();

                        continue;
                    }
                    foreach ($starts as $operation) {
                        app(StartSwampRun::class)->refuse($operation, $error);
                    }
                    $first = reset($starts);
                    throw $first === false ? $error
                        : new ResourceOperationException($error->errorCode, $error->getMessage(), $error->status, details: ['operation_id' => $first->id]);
                }
            }
            foreach (app(ProofChildStarts::class)->queue($starts) as $operation) {
                try {
                    Bus::dispatch(new StartAdmittedSwampRun($operation->id));
                } catch (Throwable $exception) {
                    Log::warning('Swamp start could not be queued', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                    app(StartSwampRun::class)->refuse($operation, new ResourceOperationException(
                        'swamp.start_enqueue_failed', 'The native workflow start could not be queued.', 503));
                }
            }
        } finally {
            $this->close();
        }
    }

    /** Seconds an admission copy waits before its one more push: only for serve's 429 that names a `Retry-After` that fits, and only off HTTP. */
    private static function admissionWait(ResourceOperationException $error): ?int
    {
        $refusal = $error->getPrevious();
        if (! $refusal instanceof WebSocketException || $refusal->getCode() !== 429 || $refusal->retryAfterSeconds === null || ! app()->runningInConsole()) {
            return null;
        }
        $seconds = $refusal->retryAfterSeconds + 1;

        return $seconds <= self::MaxAdmissionWaitSeconds ? $seconds : null;
    }
}
