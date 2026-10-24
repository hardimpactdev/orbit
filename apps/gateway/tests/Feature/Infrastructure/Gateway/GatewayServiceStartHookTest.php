<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

function start_hook_runner(?Closure $answer = null): ProcessRunner
{
    return new class($answer) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function __construct(private readonly ?Closure $answer) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;
            $arguments = $invocation->arguments;
            // The Gateway certificate publisher compares the key and the certificate by their public keys.
            if (($arguments[1] ?? null) === 'openssl' && array_intersect(['-pubout', '-pubkey'], $arguments) !== []) {
                return new CommandResult(0, "PUBLIC KEY\n", '', 2, false);
            }

            return ($this->answer === null ? null : ($this->answer)($invocation)) ?? new CommandResult(0, '', '', 2, false);
        }
    };
}

describe('production wiring', function (): void {
    beforeEach(function (): void {
        $home = sys_get_temp_dir().'/orbit-start-hook-home-'.Str::uuid();
        mkdir($home.'/ca/gateway-current', 0o700, true);
        file_put_contents($home.'/ca/gateway-current/gateway.key', 'PRIVATE KEY');
        file_put_contents($home.'/ca/gateway-current/gateway.pem', 'CERTIFICATE');
        file_put_contents($home.'/ca/root.pem', 'ROOT CERTIFICATE');
        config(['orbit.home' => $home, 'orbit.gateway_checkout' => '/home/orbit/orbit-gateway', 'orbit.gateway_web' => '/home/orbit/web']);
        $this->home = $home;
        $this->runner = start_hook_runner();
        $this->app->instance(ProcessRunner::class, $this->runner);
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
        $units = new class implements AgentViewConverger, FleetConvergeUnits, GatewayReleaseUnitConverger, RuntimeHibernatorConverger
        {
            public function converge(): void {}

            public function start(): bool
            {
                return true;
            }

            public function startLater(): bool
            {
                return true;
            }
        };
        foreach ([AgentViewConverger::class, FleetConvergeUnits::class, GatewayReleaseUnitConverger::class, RuntimeHibernatorConverger::class] as $unit) {
            $this->app->instance($unit, $units);
        }
        $this->app->forgetInstance(GatewayWebConverger::class);
        $this->gateway = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    });

    afterEach(function (): void {
        new Filesystem()->deleteDirectory($this->home);
    });

    it('installs and runs the Documents hook before the reload in Gateway web convergence', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('gateway.check-checkout', ['changed' => false])->answer('gateway.prepare-checkout', ['changed' => false])->answer('gateway.prepare-web-directory', ['changed' => false])->answer('gateway.publish-certificate', ['changed' => false])->answer('caddy.install-package', ['changed' => false])->answer('caddy.order-after-vpn', ['changed' => false])->answer('gateway.converge-fpm', ['changed' => true, 'activated' => true]);
        app()->instance(AgentCommandRunner::class, $agents);
        app(GatewayWebConverger::class)->converge($this->gateway, 'gateway.orbit', '10.44.0.2');

        expect(collect($agents->calls)->last()['input']['hooks'][0]['command'])->toBe('documents.prepare-cleanup');
        expect($agents->commands())->toBe(['gateway.check-checkout', 'caddy.install-package', 'caddy.order-after-vpn', 'gateway.prepare-checkout', 'gateway.prepare-web-directory', 'gateway.publish-certificate', 'gateway.converge-fpm']);
    });

    it('installs and runs the Documents hook before the reload in the release handoff', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('gateway.converge-fpm', ['changed' => true, 'activated' => true]);
        app()->instance(AgentCommandRunner::class, $agents);
        $result = app(GatewayRuntimeHandoff::class)->serve();

        expect($result['fpm'])->toBe('reloaded')
            ->and($agents->calls[0]['input']['hooks'][0]['command'])->toBe('documents.prepare-cleanup');
    });
});
