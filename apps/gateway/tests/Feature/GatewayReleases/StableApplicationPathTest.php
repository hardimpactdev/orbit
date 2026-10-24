<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\NativeAgentViewConverger;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;
use App\Infrastructure\Gateway\GatewayServiceCommands;
use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/orbit-stable-path-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/releases/0123456789ab/apps/gateway', 0755, true);
    symlink('releases/0123456789ab', $this->base.'/orbit');
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway', 'orbit.home' => $this->base.'/home']);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->base));
});

describe('the stable Gateway application path', function (): void {
    it('is the link to the current release, not the release it resolves to', function (): void {
        expect(GatewayApplicationPath::resolve())->toBe($this->base.'/orbit/apps/gateway');
    });

    it('is what the agent-view and hibernator units run in, so a restart after a switch runs the new release', function (): void {
        $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'status' => LifecycleStatus::Active]);
        $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $agents = (new FakeAgentCommandRunner)
            ->answer('gateway.converge-agent-view', ['changed' => true])
            ->answer('gateway.converge-hibernator', ['changed' => true]);
        $commands = new GatewayServiceCommands($agents);
        new NativeAgentViewConverger($commands)->converge();
        new NativeRuntimeHibernatorConverger($commands)->converge();

        foreach ($agents->calls as $call) {
            expect($call['input']['application'])->toBe($this->base.'/orbit/apps/gateway')
                ->and($call['input']['application'])->not->toContain('releases/0123456789ab');
        }
        expect($agents->commands())->toBe(['gateway.converge-agent-view', 'gateway.converge-hibernator']);
    });
});

it('resolves the link per request on the Gateway site PHP handler itself', function (): void {
    $site = new GatewayCaddyConfigRenderer()->render('gateway.orbit', '10.44.0.1', '/home/orbit/orbit/apps/gateway', '/home/orbit/web');

    expect($site)->toContain("php_fastcgi unix//run/php/orbit-gateway.sock {\n            resolve_root_symlink\n");
});
