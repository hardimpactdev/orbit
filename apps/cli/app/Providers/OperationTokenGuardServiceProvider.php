<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Executor\OperationTokenGuard;
use Illuminate\Support\ServiceProvider;
use Orbit\Core\Security\OperationTokenVerifier;

final class OperationTokenGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationTokenGuard::class, function (): OperationTokenGuard {
            return new OperationTokenGuard(
                secret: $this->nullableString(config('orbit.executor.shared_secret')),
                expectedNode: $this->nullableString(config('orbit.executor.node_identity')),
                verifier: new OperationTokenVerifier,
            );
        });
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
