<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Executor\OperationStdinBuffer;
use App\Services\Executor\OperationTokenGuard;
use App\Services\GatewayApiClient;
use Illuminate\Support\ServiceProvider;

final class OperationTokenGuardServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(OperationStdinBuffer::class);

        $this->app->singleton(OperationTokenGuard::class, fn (): OperationTokenGuard => new OperationTokenGuard(
            resolveGateway: fn (): GatewayApiClient => $this->app->make(GatewayApiClient::class),
            stdinBuffer: $this->app->make(OperationStdinBuffer::class),
        ));
    }
}
