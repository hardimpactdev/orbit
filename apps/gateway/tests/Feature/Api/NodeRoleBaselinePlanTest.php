<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\NodeRoleBaselinePlan;
use App\Domain\Nodes\RoleName;
use App\Models\Cluster;
use App\Models\NodeRole;
use Tests\Support\Fleet\FleetFixtures;

it('delegates integration effects to exactly the six owned role kinds', function (RoleName $role, bool $remove): void {
    $node = FleetFixtures::node('role-plan-integration', [$role]);
    $assignment = $node->roles->sole();
    $planner = app(NodeRoleBaselinePlan::class);
    $plan = $remove ? $planner->remove($node, $assignment, true, true)
        : $planner->converge($node, $assignment, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

    expect($plan)->toBe([['type' => 'child', 'key' => 'role-runtime', 'kind' => $role->value.($remove ? '.role_remove' : '.role_converge'),
        'node_id' => $node->id, 'input' => $remove ? ['purge_data' => true, 'offline' => true] : [], 'optional' => false]]);
    expect($assignment->fresh()->node_id)->toBe($node->id);
})->with([
    [RoleName::Metrics, false], [RoleName::Analytics, false], [RoleName::WebSocket, false],
    [RoleName::Metrics, true], [RoleName::Analytics, true], [RoleName::WebSocket, true],
]);

it('keeps app-dev storage DNS hibernation Caddy and firewall in the proven order', function (): void {
    $vpn = FleetFixtures::node('role-plan-vpn', [RoleName::Vpn]);
    $node = FleetFixtures::node('role-plan-app-dev', [RoleName::AppDev]);
    $plan = app(NodeRoleBaselinePlan::class)->converge($node, $node->roles->sole(), new ManagedUserAccount('orbit', 'web', '/srv/orbit'));

    expect(array_column($plan, 'key'))->toBe(['role-storage', 'role-private-dns', 'role-caddy-package', 'role-prerequisites',
        'role-javascript', 'role-hibernation', 'role-caddy', 'role-firewall', 'role-manager-vp', 'role-manager-composer']);
    expect($plan[0]['input'])->toMatchArray(['user' => 'orbit', 'group' => 'web', 'create_missing' => true]);
    expect($plan[1])->toMatchArray(['kind' => 'route.private_dns_publish', 'node_id' => $vpn->id]);
    expect($plan[3]['input'])->toMatchArray(['user' => 'orbit', 'group' => 'web', 'home' => '/srv/orbit']);
});

it('keeps per-VM configuration while skipping software already provided by a task image', function (): void {
    $node = FleetFixtures::taskVm();
    $plan = app(NodeRoleBaselinePlan::class)->converge($node, $node->roles->sole(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

    expect(array_column($plan, 'key'))->toBe(['role-storage', 'role-hibernation', 'role-caddy', 'role-firewall', 'role-manager-vp', 'role-manager-composer']);
    expect(array_column(array_slice($plan, -2), 'input'))->toBe([
        ['manager' => 'vp', 'image_provides_manager' => true], ['manager' => 'composer', 'image_provides_manager' => true],
    ]);
});

it('installs Gateway Caddy before firewall grants and makes DNS routing a soft follow-up', function (): void {
    $vpn = FleetFixtures::node('role-plan-gateway-vpn', [RoleName::Vpn]);
    $node = FleetFixtures::node('role-plan-gateway', [RoleName::Gateway]);
    $planner = app(NodeRoleBaselinePlan::class);
    $plan = $planner->converge($node, $node->roles->sole(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

    expect(array_column($plan, 'key'))->toBe(['role-caddy-package', 'role-caddy-ordering', 'role-firewall', 'role-gateway-access', 'role-private-dns', 'role-gateway-dns']);
    expect($plan[5])->toMatchArray(['name' => 'wireguard.gateway-dns', 'optional' => true,
        'input' => ['action' => 'converge', 'address' => $vpn->wireguard_ip, 'domain' => 'orbit']]);
    $removal = $planner->remove($node, $node->roles->sole(), false, false);
    expect($removal[0])->toMatchArray(['name' => 'wireguard.gateway-dns', 'input' => ['action' => 'remove'], 'optional' => false]);
});

it('verifies VPN PHP with disabled FPM before conditionally republishing private DNS', function (): void {
    $node = FleetFixtures::node('role-plan-vpn-php', [RoleName::Vpn]);
    $plan = app(NodeRoleBaselinePlan::class)->converge($node, $node->roles->sole(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

    expect(array_column($plan, 'key'))->toBe(['role-prerequisites', 'role-php', 'role-firewall', 'role-private-dns']);
    expect($plan[1]['input'])->toMatchArray(['fpm' => 'disabled', 'extras' => 'keep']);
    expect($plan[3]['when'])->toBe(['key' => 'role-php', 'result' => ['outcome' => 'applied']]);
});

it('keeps offline local runtimes and software while withdrawing only remote DNS intent', function (RoleName $role, bool $publishes): void {
    $vpn = FleetFixtures::node('role-plan-offline-vpn', [RoleName::Vpn]);
    $node = FleetFixtures::node('role-plan-offline');
    $cluster = $role === RoleName::Ingress ? Cluster::query()->create(['name' => 'role-plan-ingress']) : null;
    if ($cluster !== null) {
        $node->update(['cluster_id' => $cluster->id]);
    }
    NodeRole::query()->create(['node_id' => $node->id, 'cluster_id' => $cluster?->id, 'role' => $role, 'status' => 'active']);
    $node->load('roles');
    $plan = app(NodeRoleBaselinePlan::class)->remove($node, $node->roles->sole(), true, true);

    expect(array_column($plan, 'kind'))->toBe($publishes ? ['route.private_dns_publish'] : []);
    expect(array_column($plan, 'node_id'))->toBe($publishes ? [$vpn->id] : []);
})->with([[RoleName::AppDev, true], [RoleName::Gateway, true], [RoleName::AppProd, false], [RoleName::Database, false], [RoleName::Ingress, false]]);

it('prepares a database role without changing routing or role-independent Tools', function (): void {
    $node = FleetFixtures::node('role-plan-database', [RoleName::Database]);
    $planner = app(NodeRoleBaselinePlan::class);
    $plan = $planner->converge($node, $node->roles->sole(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

    expect(array_column($plan, 'name'))->toBe(['role.install-prerequisites']);
    expect($planner->remove($node, $node->roles->sole(), true, false))->toBe([]);
});

it('orders CI prerequisites before the host and firewall and retains data unless purge is explicit', function (): void {
    $node = FleetFixtures::node('role-plan-ci', [RoleName::CiRunner]);
    $assignment = $node->roles->sole();
    $planner = app(NodeRoleBaselinePlan::class);
    $plan = $planner->converge($node, $assignment, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

    expect(array_column($plan, 'key'))->toBe(['role-prerequisites', 'role-ci-runner', 'role-firewall']);
    expect($plan[0]['input']['packages'])->toContain('incus', 'incus-base', 'incus-client')->not->toContain('docker.io', 'caddy');
    expect($planner->remove($node, $assignment, false, false)[0])->toMatchArray(['name' => 'ci-runner.host', 'input' => ['action' => 'remove'], 'result' => ['outcome' => 'retained']]);
    expect($planner->remove($node, $assignment, true, false)[0])->toMatchArray(['input' => ['action' => 'purge'], 'result' => ['outcome' => 'removed']]);
    expect($planner->remove($node, $assignment, false, true))->toBe([]);
});
