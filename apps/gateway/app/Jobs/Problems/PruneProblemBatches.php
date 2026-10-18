<?php

declare(strict_types=1);

namespace App\Jobs\Problems;

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

final class PruneProblemBatches implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = ProblemCollectionJob::Timeout;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    public function handle(): void
    {
        Artisan::call('queue:prune-batches', ['--hours' => 24, '--unfinished' => 24, '--cancelled' => 24]);
    }
}
