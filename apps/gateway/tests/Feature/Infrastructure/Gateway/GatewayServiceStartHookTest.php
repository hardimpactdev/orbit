<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

describe('production wiring', function (): void {
    beforeEach(function (): void {
        $home = sys_get_temp_dir().'/orbit-start-hook-home-'.Str::uuid();
        mkdir($home.'/ca/gateway-current', 0o700, true);
        file_put_contents($home.'/ca/gateway-current/gateway.key', 'PRIVATE KEY');
        file_put_contents($home.'/ca/gateway-current/gateway.pem', 'CERTIFICATE');
        file_put_contents($home.'/ca/root.pem', 'ROOT CERTIFICATE');
        config(['orbit.home' => $home, 'orbit.gateway_checkout' => '/home/orbit/orbit-gateway', 'orbit.gateway_web' => '/home/orbit/web']);
        $this->home = $home;
        $this->app->instance(GatewayCertificateIssuer::class, new readonly class($home) implements GatewayCertificateIssuer
        {
            public function __construct(private string $home) {}

            public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
            {
                return new GatewayCertificatePaths($this->home.'/ca/gateway-current/gateway.key', $this->home.'/ca/gateway-current/gateway.pem');
            }
        });
        $this->app->instance(NodeCaddyBuilds::class, new class implements NodeCaddyBuilds
        {
            public function build(Node $node): NodeCaddyBuildResult
            {
                return NodeCaddyBuildResult::Published;
            }

            public function checkListenAddresses(Node $node): void {}
        });
        $units = new class implements AgentViewConverger, GatewayReleaseUnitConverger, RuntimeHibernatorConverger
        {
            public function converge(): void {}
        };
        foreach ([AgentViewConverger::class, GatewayReleaseUnitConverger::class, RuntimeHibernatorConverger::class] as $unit) {
            $this->app->instance($unit, $units);
        }
        $this->gateway = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $this->gateway->forceFill(['architecture' => 'x86_64', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key'])->save();
        $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    });

    afterEach(function (): void {
        new Filesystem()->deleteDirectory($this->home);
    });

    it('installs and runs the Documents hook before the reload in the release handoff', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('gateway.converge-fpm', ['changed' => true, 'activated' => true]);
        app()->instance(AgentCommandRunner::class, $agents);
        config(['swamp.run_token' => 'fixture-token', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/root.pem']);
        app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
        $result = app(GatewayRuntimeHandoff::class)->serve();

        expect($result['fpm'])->toBe('reloaded')
            ->and($agents->calls[0]['input']['hooks'][0]['command'])->toBe('documents.prepare-cleanup');
    });
});
