<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\GatewayWebCaddySiteSource;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\NativeGatewaySelfAccessConverger;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\NativeGatewayVpnConverger;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class GatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([GatewayWebCaddySiteSource::class], NodeCaddySiteSource::class);
        $this->app->singleton(
            GatewayVpnConverger::class,
            static fn (): GatewayVpnConverger => new NativeGatewayVpnConverger(
                renderer: app(WireGuardServerConfigRenderer::class),
                files: app(ProtectedFileWriter::class),
                processes: app(ProcessRunner::class),
                agents: app(AgentCommandRunner::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            GatewaySelfAccessConverger::class,
            static fn (): GatewaySelfAccessConverger => new NativeGatewaySelfAccessConverger(
                agents: app(AgentCommandRunner::class),
                knownHosts: app(KnownHostsStore::class),
                sshKeys: app(SshKeyProvider::class),
            ),
        );

    }
}
