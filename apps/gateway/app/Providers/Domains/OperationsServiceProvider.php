<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Queue::before(static function (): void {
            app(SwampConnection::class)->releaseIdle();
        });
    }

    public function register(): void
    {
        $this->app->singleton(OperationKinds::class);
        $this->app->singleton(DirectOperation::class);
        $this->app->scoped(OperationIdempotency::class);
        $this->app->singleton(SwampRunSubscriber::class, static fn ($app): SwampRunSubscriber => new SwampRunSubscriber(
            StreamWebSocketClient::forSwamp(),
            $app->make(LoggerInterface::class),
        ));
    }
}
