<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Jobs;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Orbit\Extensions\Conn\Domain\ConnThreadIndex;
use Orbit\Extensions\Conn\Domain\ConnThreadLibrarian;

final class PollConnHostThreadsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 15;

    public int $uniqueFor = 30;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    public function handle(ExtensionStore $extensions, ConnThreadIndex $index, ConnThreadLibrarian $librarian): void
    {
        if ($extensions->enabled('conn')) {
            $index->hostPass($librarian);
        }
    }
}
