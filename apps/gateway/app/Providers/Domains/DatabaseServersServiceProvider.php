<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\DatabaseServerProcessOwnership;
use App\Domain\DatabaseServers\PostgresConnectionUpdateHook;
use App\Domain\DatabaseServers\ValkeyConnectionUpdateHook;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Infrastructure\DatabaseServers\NativeDatabaseRemovalLock;
use App\Infrastructure\DatabaseServers\PredisValkeyServerAdmin;
use App\Infrastructure\DatabaseServers\RemoteDatabaseServerAdmin;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class DatabaseServersServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        DatabaseServerAdmin::class => RemoteDatabaseServerAdmin::class,
        ValkeyServerAdmin::class => PredisValkeyServerAdmin::class,
    ];

    public function register(): void
    {
        $this->app->tag([DatabaseIntentSource::class], OperationSecretSource::class);
        $this->app->tag([ValkeyConnectionUpdateHook::class], DatabaseConnectionUpdateHook::class);
        $this->app->tag([PostgresConnectionUpdateHook::class], DatabaseConnectionUpdateHook::class);
        $this->app->tag([DatabaseServerProcessOwnership::class], ProcessRemovalGuard::class);
        $this->app->scoped(
            DatabaseRemovalLock::class,
            static fn (): DatabaseRemovalLock => new NativeDatabaseRemovalLock(
                directory: rtrim(Config::string('orbit.home'), '/').'/locks/database-removal',
            ),
        );
    }
}
