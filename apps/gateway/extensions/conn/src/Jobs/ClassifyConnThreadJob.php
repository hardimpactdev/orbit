<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Jobs;

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Orbit\Extensions\Conn\Domain\ConnThreadLibrarian;

final class ClassifyConnThreadJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function __construct(
        public int $environmentId,
        public string $threadId,
        public string $connProjectId,
        public string $connWorkspaceRoot,
        public int $firstMessageCutoff,
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
}
