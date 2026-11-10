<?php

declare(strict_types=1);

namespace App\Jobs\Instances;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Deployment\DevelopmentDeployPrecheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class CheckDevelopmentDeployments implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1620;

    public int $uniqueFor = 1680;

    public function __construct()
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping)->dontRelease()->expireAfter($this->uniqueFor)];
    }

    public function handle(DevelopmentDeployPrecheck $precheck): void
    {
        $precheck->check();
    }
}
