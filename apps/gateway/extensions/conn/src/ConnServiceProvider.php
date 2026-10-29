<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Activity\ActivityInputRedaction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecretSource;
use App\Providers\ExtensionServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Orbit\Extensions\Conn\Console\Commands\CheckConnOperationsCommand;
use Orbit\Extensions\Conn\Domain\ConnOperationSecrets;
use Orbit\Extensions\Conn\Domain\ConnPairingCredentialStore;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Infrastructure\ConnActivityInputRedaction;
use Orbit\Extensions\Conn\Infrastructure\HttpT3ServerClient;

/** The conn extension: Conn devices, shared profiles, and T3 server pairing ([Conn layer](/reference/conn)). */
final class ConnServiceProvider extends ExtensionServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        T3ServerClient::class => HttpT3ServerClient::class,
    ];

    public function name(): string
    {
        return 'conn';
    }

    public function description(): string
    {
        return 'Conn devices sign in as Nodes, share settings through profiles, and pair with registered T3 servers.';
    }

    public function register(): void
    {
        $this->contribute(OperationSecretSource::class, ConnOperationSecrets::class);
        $this->contribute(OperationSecretOutputStore::class, ConnPairingCredentialStore::class);
        $this->contribute(ActivityInputRedaction::class, ConnActivityInputRedaction::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->commands([CheckConnOperationsCommand::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            GatewaySchedule::command($schedule, 'orbit:conn-operations-check', uniqueFor: 120, overlapMinutes: 2)->everyMinute()
                ->when(static fn (): bool => app(ExtensionStore::class)->enabled('conn'));
        });
    }
}
