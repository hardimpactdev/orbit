<?php

declare(strict_types=1);

namespace App\Jobs\Problems;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\Skip;

abstract class ProblemCollectionJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public const int ProbeSeconds = 12;

    public const int Timeout = self::ProbeSeconds + 1;

    public int $tries = 1;

    public int $timeout = self::Timeout;

    public bool $failOnTimeout = true;

    /** @return list<Skip> */
    public function middleware(): array
    {
        return [Skip::unless(app(TaskExtensionState::class)->enabled())];
    }

    /** @param list<int> $nodeIds */
    public function __construct(public string $collection, public array $nodeIds)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Problems->value);
    }
}
