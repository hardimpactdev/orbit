<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Infrastructure\Swamp\SwampScheduledResults;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationKinds::class);
        $this->app->singleton(DirectOperation::class);
        $this->app->scoped(OperationIdempotency::class);
        $this->app->singleton(SwampRunSubscriber::class, static fn ($app): SwampRunSubscriber => new SwampRunSubscriber(
            StreamWebSocketClient::forSwamp(),
            $app->make(LoggerInterface::class),
            scheduled: new SwampScheduledResults(StreamWebSocketClient::forSwamp(), $app->make(LoggerInterface::class)),
        ));
    }
}
