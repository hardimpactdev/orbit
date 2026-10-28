<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Infrastructure\Ssh\GatewaySshKeys;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsRepository;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshHostKeyScanner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmCredentialGuard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class SshServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        HostKeyScanner::class => SshHostKeyScanner::class,
        SshExecutor::class => TaskVmCredentialGuard::class,
    ];

    public function register(): void
    {
        $this->app->singleton(
            KnownHostsRepository::class,
            static fn (): KnownHostsRepository => new KnownHostsRepository(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/ssh/known_hosts',
            ),
        );
        $this->app->alias(KnownHostsRepository::class, KnownHostsStore::class);
        $this->app->singleton(
            GatewaySshKeys::class,
            static fn (): GatewaySshKeys => new GatewaySshKeys(
                rtrim(string: Config::string('orbit.home'), characters: '/').'/ssh/id_ed25519',
            ),
        );
        $this->app->alias(GatewaySshKeys::class, SshKeyProvider::class);
    }
}
