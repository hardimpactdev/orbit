<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Providers\ExtensionServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Orbit\Extensions\Vault\Console\Commands\VaultSyncCommand;
use Orbit\Extensions\Vault\Domain\VaultClient;
use Orbit\Extensions\Vault\Infrastructure\BitwardenCrypto;
use Orbit\Extensions\Vault\Infrastructure\HttpVaultClient;

/** The vault extension: database credentials published to Vaultwarden ([Vaultwarden](/reference/vaultwarden)). */
final class VaultServiceProvider extends ExtensionServiceProvider
{
    public function name(): string
    {
        return 'vault';
    }

    public function description(): string
    {
        return 'Database credentials published as Database items in a Vaultwarden collection, kept current by the Gateway.';
    }

    public function register(): void
    {
        $this->app->bind(
            VaultClient::class,
            static fn (): VaultClient => new HttpVaultClient(
                crypto: app(BitwardenCrypto::class),
                certificates: app(LeafCertificateSigner::class),
                rootCertificatePath: rtrim(string: Config::string('orbit.home'), characters: '/').'/ca/root.pem',
            ),
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->commands([VaultSyncCommand::class]);
        // Publishing is the feature itself, so the sync runs only while the switch is on. The next run after the switch
        // turns on again catches up: it creates, rewrites, and deletes whatever changed meanwhile.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            GatewaySchedule::command($schedule, 'orbit:vault-sync', uniqueFor: 900, overlapMinutes: 15)->everyMinute()
                ->when(static fn (): bool => app(ExtensionStore::class)->enabled('vault'));
        });
    }
}
