<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Operation;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Swamp network work that an admission needs runs after its transaction commits, never under the SQLite write lock.
 * Execution copies push first. Admitted starts are queued only when every copy is stored; a failed copy fails them unstarted.
 * Outside a transaction copies push and starts queue at once. A rolled-back transaction discards its work.
 *
 * One dispatch shares one WebSocket for its copies, because serve rate-limits connections per client and
 * matches each reply to its request by `id`. A request that fails drops the shared connection; the next one opens a
 * new one.
 */
#[Scoped]
final class SwampDispatch
{
    /** @var array<int, Closure(): void> */
    private array $copies = [];

    /** @var array<int, Operation> */
    private array $starts = [];

    private int $next = 0;

    /** Whether a dispatch is running, so its copies share the connection owner. */
    private bool $sharing = false;

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

    /**
     * A connected socket to `$endpoint` and whether it is the dispatch's shared one, which the caller must not close.
     * Outside a dispatch it connects `$own`.
     *
     * @return array{0: WebSocketClient, 1: bool}
     */
    public function socket(WebSocketEndpoint $endpoint, float $timeoutSeconds, WebSocketClient $own): array
    {
        if (! $this->sharing) {
            $own->connect($endpoint, $timeoutSeconds);

            return [$own, false];
        }

        return [app(SwampConnection::class)->socket($endpoint, $timeoutSeconds, $own), true];
    }

    /** Closes the shared connection, after a request on it failed or when the dispatch ends. */
    public function release(): void
    {
        app(SwampConnection::class)->release();
    }

    private function dispatch(): void
    {
        [$copies, $starts] = [$this->copies, $this->starts];
        $this->copies = $this->starts = [];
        $this->sharing = true;
        try {
            try {
                foreach ($copies as $push) {
                    $push();
                }
            } catch (ResourceOperationException $error) {
                foreach ($starts as $operation) {
                    app(StartSwampRun::class)->refuse($operation, $error);
                }
                $first = reset($starts);
                throw $first === false ? $error
                    : new ResourceOperationException($error->errorCode, $error->getMessage(), $error->status, details: ['operation_id' => $first->id]);
            }
            foreach ($starts as $operation) {
                try {
                    Bus::dispatch(new StartAdmittedSwampRun($operation->id));
                } catch (Throwable $exception) {
                    Log::warning('Swamp start could not be queued', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                    app(StartSwampRun::class)->refuse($operation, new ResourceOperationException(
                        'swamp.start_enqueue_failed', 'The native workflow start could not be queued.', 503));
                }
            }
        } finally {
            $this->sharing = false;
            $this->release();
        }
    }
}
