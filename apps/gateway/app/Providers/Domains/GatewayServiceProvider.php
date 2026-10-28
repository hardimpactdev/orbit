<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Actions\Gateway\BootstrapGatewayAction;
use App\Actions\Gateway\GatewayBootstrapIdentityValidator;
use App\Actions\Gateway\GatewayOperatingSystemGuard;
use App\Actions\Nodes\AssignRoleAction;
use App\Domain\AgentView\AgentViewConverger;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\GatewayWebCaddySiteSource;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\GatewayWebDirectoryConverger;
use App\Infrastructure\Gateway\NativeGatewayCaddyInstaller;
use App\Infrastructure\Gateway\NativeGatewayCertificatePublisher;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Gateway\NativeGatewaySelfAccessConverger;
use App\Infrastructure\Gateway\NativeGatewayWebConverger;
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
            GatewayWebConverger::class,
            static fn (): GatewayWebConverger => new NativeGatewayWebConverger(
                certificates: app(GatewayCertificateIssuer::class),
                fpmRenderer: app(GatewayFpmConfigRenderer::class),
                checkout: new GatewayCheckoutAccessConverger(
                    agents: app(AgentCommandRunner::class),
                    checkoutPath: rtrim(string: Config::string('orbit.gateway_checkout'), characters: '/'),
                ),
                webDirectory: new GatewayWebDirectoryConverger(
                    agents: app(AgentCommandRunner::class),
                    webRoot: Config::string('orbit.gateway_web'),
                ),
                certificatePublisher: new NativeGatewayCertificatePublisher(
                    agents: app(AgentCommandRunner::class),
                    orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
                ),
                fpm: app(NativeGatewayFpmConverger::class),
                builds: app(NodeCaddyBuilds::class),
                caddyInstaller: new NativeGatewayCaddyInstaller(app(AgentCommandRunner::class)),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
                checkoutPath: rtrim(string: Config::string('orbit.gateway_checkout'), characters: '/'),
                hibernator: app(RuntimeHibernatorConverger::class),
                agentView: app(AgentViewConverger::class),
                releaseUnits: app(GatewayReleaseUnitConverger::class),
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
        $this->app->singleton(
            BootstrapGatewayAction::class,
            static fn (): BootstrapGatewayAction => new BootstrapGatewayAction(
                agent: app(NodeAgentRuntime::class),
                assignRole: app(AssignRoleAction::class),
                identity: app(GatewayBootstrapIdentityValidator::class),
                operatingSystem: app(GatewayOperatingSystemGuard::class),
                vpnSettings: app(VpnSettings::class),
                processes: app(ProcessRunner::class),
                commands: app(AgentCommandRunner::class),
                vpn: app(GatewayVpnConverger::class),
                web: app(GatewayWebConverger::class),
                selfAccess: app(GatewaySelfAccessConverger::class),
                dns: app(PrivateDnsManager::class),
                orbitHome: rtrim(string: Config::string('orbit.home'), characters: '/'),
            ),
        );
    }
}
