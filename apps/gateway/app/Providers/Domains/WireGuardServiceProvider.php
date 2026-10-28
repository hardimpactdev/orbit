<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\NativeWireGuardPeerConverger;
use App\Infrastructure\WireGuard\NativeWireGuardPeerDnsRepairer;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class WireGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            VpnConfigurationRepository::class,
            static fn (): VpnConfigurationRepository => new VpnConfigurationRepository(
                app(VpnSettings::class),
                rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->singleton(
            NativeGatewayPeerProjectionManager::class,
            static fn (): NativeGatewayPeerProjectionManager => new NativeGatewayPeerProjectionManager(
                configuration: app(VpnConfigurationRepository::class),
                serverRenderer: app(WireGuardServerConfigRenderer::class),
                files: app(ProtectedFileWriter::class),
                agents: app(AgentCommandRunner::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
        $this->app->alias(NativeGatewayPeerProjectionManager::class, GatewayPeerProjectionManager::class);
        $this->app->singleton(
            NativeWireGuardPeerConverger::class,
            static fn (): NativeWireGuardPeerConverger => new NativeWireGuardPeerConverger(
                configuration: app(VpnConfigurationRepository::class),
                gatewayPeers: app(GatewayPeerProjectionManager::class),
                agents: app(AdmissionAgentRunner::class),
            ),
        );
        $this->app->alias(NativeWireGuardPeerConverger::class, WireGuardPeerConverger::class);
        $this->app->singleton(
            NativeWireGuardPeerDnsRepairer::class,
            static fn (): NativeWireGuardPeerDnsRepairer => new NativeWireGuardPeerDnsRepairer(
                configuration: app(VpnConfigurationRepository::class),
                agents: app(AgentCommandRunner::class),
            ),
        );
        $this->app->alias(NativeWireGuardPeerDnsRepairer::class, WireGuardPeerDnsRepairer::class);
    }
}
