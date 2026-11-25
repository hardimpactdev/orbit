<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Jobs;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Orbit\Extensions\Conn\Domain\ConnThreadLibrarian;
use Throwable;

final class ClassifyConnThreadJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(
        public int $environmentId,
        public string $threadId,
        public string $connProjectId,
        public string $connWorkspaceRoot,
    ) {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    public function uniqueId(): string
    {
        return $this->environmentId.':'.$this->threadId;
    }

    public function handle(ConnThreadLibrarian $librarian): void
    {
        $librarian->classify($this);
    }

    public function failed(Throwable $error): void
    {
        $errorCode = match (true) {
            $error instanceof ResourceOperationException => $error->errorCode,
            $error instanceof TimeoutExceededException => 'conn.librarian_timeout',
            default => 'conn.librarian_job_failed',
        };
        app(ConnThreadLibrarian::class)->giveUp($this, $errorCode);
    }
}
