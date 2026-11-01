<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

require_once dirname(__DIR__, 4).'/e2e/resources/guest/topology-gateway-services.php';

it('starts mounted Gateway services with canonical configs and no release or GitHub calls', function (): void {
    $root = sys_get_temp_dir().'/orbit-mounted-gateway-'.bin2hex(random_bytes(8));
    mkdir($root.'/ca', 0700, true);
    file_put_contents($root.'/ca/root.pem', 'fixture-root');
    file_put_contents($root.'/key', 'fixture-private');
    file_put_contents($root.'/cert', 'fixture-certificate');
    config()->set(['orbit.home' => $root, 'orbit.gateway_checkout' => $root.'/checkout/apps/gateway', 'orbit.gateway_web' => '/home/orbit/web']);
    $node = Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.10']);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $issuer = Mockery::mock(GatewayCertificateIssuer::class);
    $issuer->shouldReceive('issue')->once()->with('gateway.orbit', '10.44.0.1')->andReturn(new GatewayCertificatePaths($root.'/key', $root.'/cert'));
    app()->instance(GatewayCertificateIssuer::class, $issuer);
    $agents = new FakeAgentCommandRunner()
        ->answer('gateway.prepare-checkout', ['changed' => true])
        ->answer('gateway.prepare-web-directory', ['changed' => true])
        ->answer('gateway.publish-certificate', ['changed' => true])
        ->answer('gateway.converge-fpm', ['changed' => true, 'activated' => true]);
    app()->instance(AgentCommandRunner::class, $agents);

    $caddy = configureMountedTopologyGateway(app());

    expect(array_column($agents->calls, 'command'))->toBe(['gateway.prepare-checkout', 'gateway.prepare-web-directory', 'gateway.publish-certificate', 'gateway.converge-fpm'])
        ->and($agents->calls[0]['input']['path'])->toBe($root.'/checkout/apps/gateway')
        ->and($agents->calls[3]['input']['pool'])->toContain('chdir = '.$root.'/checkout/apps/gateway', 'env[ORBIT_HOME] = '.$root)
        ->and($caddy)->toContain('# Managed by Orbit: Node Caddy build', 'bind 10.44.0.1', 'root * '.$root.'/checkout/apps/gateway/public')
        ->not->toContain('fixture-private');
});

it('refuses missing fixture Gateway identity before any service effect', function (): void {
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    expect(fn () => configureMountedTopologyGateway(app()))->toThrow(RuntimeException::class, 'e2e.fixture_gateway_identity_invalid');
    expect($agents->calls)->toBe([]);
});
