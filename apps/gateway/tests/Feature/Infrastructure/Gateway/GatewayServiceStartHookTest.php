<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
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

describe('compiled startup hook intent', function (): void {
    it('sends only registered command names and configuration and runs them only when requested', function (bool $run): void {
        config(['orbit.gateway_checkout' => '/home/orbit/gateway', 'orbit.home' => '/home/orbit/.orbit']);
        $agents = new FakeAgentCommandRunner()->answer('gateway.converge-start-hooks', ['changed' => true]);
        $processes = start_hook_runner();
        $converger = new NativeGatewayFpmConverger($processes, $agents, [...app()->tagged(GatewayServiceStartHook::class)]);

        $converger->installStartHooks(new Node(['name' => 'gateway']), $run);

        expect($agents->calls)->toBe([['node' => 'gateway', 'command' => 'gateway.converge-start-hooks', 'input' => [
            'hooks' => [['command' => 'documents.prepare-cleanup', 'input' => ['checkout' => '/home/orbit/gateway', 'orbit_home' => '/home/orbit/.orbit']]],
            'run' => $run,
        ]]])->and($processes->calls)->toBe([]);
    })->with([false, true]);

    it('sends an empty contribution set so native convergence retires removed extensions', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('gateway.converge-start-hooks', ['changed' => true]);
        new NativeGatewayFpmConverger(start_hook_runner(), $agents, [])->installStartHooks(new Node(['name' => 'gateway']));

        expect($agents->calls[0]['input'])->toBe(['hooks' => [], 'run' => false]);
    });

    it('maps native startup failures and prevents pool publication or activation', function (string $cause, string $step, string $code): void {
        $agents = new FakeAgentCommandRunner()->refuse('gateway.converge-start-hooks', $cause);
        $processes = start_hook_runner();
        $converger = new NativeGatewayFpmConverger($processes, $agents, []);

        expect(fn () => $converger->converge(new Node(['name' => 'gateway']), '/tmp/pool'))
            ->toThrow(fn (NodeProvisioningException $error) => expect([$error->step, $error->errorCode])->toBe([$step, $code]));
        $commands = array_map(fn (ProcessInvocation $call): string => implode(' ', $call->arguments), $processes->calls);
        expect(implode("\n", $commands))->not->toContain('sudo mv', 'systemctl');
    })->with([
        ['gateway.start_hooks_invalid', 'gateway-fpm-start-hook-validate', 'gateway.fpm_config_invalid'],
        ['gateway.start_hooks_unsafe', 'gateway-fpm-start-hook-install', 'gateway.fpm_config_install_failed'],
        ['gateway.start_hooks_failed', 'gateway-fpm-start-hook-install', 'gateway.fpm_config_install_failed'],
        ['gateway.start_hook_failed', 'gateway-fpm-start-hook', 'gateway.fpm_start_failed'],
    ]);
});

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
        $agents = new FakeAgentCommandRunner()->answer('gateway.check-checkout', ['changed' => false])->answer('gateway.prepare-checkout', ['changed' => false])->answer('gateway.prepare-web-directory', ['changed' => false])->answer('gateway.publish-certificate', ['changed' => false])->answer('caddy.install-package', ['changed' => false])->answer('caddy.order-after-vpn', ['changed' => false])->answer('gateway.converge-start-hooks', ['changed' => true]);
        app()->instance(AgentCommandRunner::class, $agents);
        app(GatewayWebConverger::class)->converge($this->gateway, 'gateway.orbit', '10.44.0.2');

        expect(collect($agents->calls)->last()['input']['run'])->toBeTrue()
            ->and(collect($this->runner->calls)->last()->arguments)->toBe(['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm']);
        expect($agents->commands())->toBe(['gateway.check-checkout', 'caddy.install-package', 'caddy.order-after-vpn', 'gateway.prepare-checkout', 'gateway.prepare-web-directory', 'gateway.publish-certificate', 'gateway.converge-start-hooks']);
    });

    it('installs and runs the Documents hook before the reload in the release handoff', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('gateway.converge-start-hooks', ['changed' => true]);
        app()->instance(AgentCommandRunner::class, $agents);
        $result = app(GatewayRuntimeHandoff::class)->serve();

        expect($result['fpm'])->toBe('reloaded')
            ->and($agents->calls[0]['input']['run'])->toBeTrue();
    });
});
