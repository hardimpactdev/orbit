<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\NodeEnrollmentPlan;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRoleBaselinePlan;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use Tests\Support\Fleet\FleetFixtures;

it('orders committed enrollment and serving listener preparation before DNS for Linux and TLD-only changes', function (string $mode): void {
    $source = FleetFixtures::node('dns-order-source');
    $source->update(['tld' => 'before.test']);
    $vpn = FleetFixtures::node('dns-order-vpn', [RoleName::Vpn]);
    $gateway = FleetFixtures::node('dns-order-gateway', [RoleName::Gateway]);
    $pending = FleetFixtures::node('dns-order-pending', [RoleName::Gateway]);
    $pending->roles()->update(['status' => LifecycleStatus::Provisioning]);
    $plan = app(NodeEnrollmentPlan::class)->build($source, ['mode' => $mode, 'roleless_operator' => true],
        ['route_changes' => [['route_id' => 777, 'domain' => 'generated.after.test', 'node_id' => $source->id]], 'roles' => [], 'settings_provided' => false, 'task_vm_id' => null]);
    $keys = array_column($plan, 'key');

    expect($keys[0])->toBe('add-route-777');
    foreach ([$vpn] as $serving) {
        $footprint = array_search('add-dns-footprint-'.$serving->id, $keys, true);
        $publication = array_search('add-dns-'.$serving->id, $keys, true);
        expect($footprint)->toBeGreaterThan(array_search('add-activate', $keys, true));
        expect($publication)->toBe($footprint + 1);
        expect($plan[$footprint])->toMatchArray(['kind' => 'node.converge', 'node_id' => $serving->id]);
        expect($plan[$publication])->toMatchArray(['kind' => 'route.private_dns_publish', 'node_id' => $serving->id, 'input' => ['reason' => 'node enrollment']]);
    }
    expect($keys)->not->toContain('add-dns-'.$pending->id);
    expect($keys)->not->toContain('add-dns-'.$gateway->id);
    expect($keys)->not->toContain('add-dns-footprint-'.$gateway->id);
    expect($source->refresh()->tld)->toBe('before.test');
})->with(['linux', 'tld_only']);

it('keeps listener scope and renders units only for active VPN owners', function (array $roles, bool $active, bool $expected): void {
    $node = FleetFixtures::node('dns-footprint-roles', $roles);
    if (! $active) {
        $node->roles()->update(['status' => LifecycleStatus::Provisioning]);
        $node->load('roles');
    }
    $dns = Mockery::mock(PrivateDnsManager::class);
    $dns->shouldNotReceive('converge');
    $artifact = new PrivateDnsFootprintArtifact([]);

    expect($artifact->applies($node))->toBe($active && in_array(RoleName::Vpn, $roles, true) && ! in_array(RoleName::Gateway, $roles, true));
    if ($expected) {
        $input = app(NodePrivateDnsListenerFootprint::class)->input($node);
        expect($input['files'])->not->toBeEmpty();
        expect($input['service'])->toContain('/var/lib/orbit/private-dns/releases/'.$input['release_id']);
        expect($input['socket'])->toContain($node->wireguard_ip);
    }
})->with([
    [[RoleName::Vpn], true, true], [[RoleName::Gateway], true, false], [[RoleName::Vpn, RoleName::Gateway], true, true],
    [[RoleName::Gateway], false, false], [[RoleName::Vpn], false, false], [[RoleName::AppProd], true, false],
]);

it('does not select a provisioning VPN as a role DNS publisher', function (): void {
    $vpn = FleetFixtures::node('dns-plan-pending-vpn', [RoleName::Vpn], status: LifecycleStatus::Provisioning);
    $source = FleetFixtures::node('dns-plan-app-dev', [RoleName::AppDev]);
    $plan = app(NodeRoleBaselinePlan::class)->converge($source, $source->roles->sole(), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));

    expect(array_column($plan, 'kind'))->not->toContain('route.private_dns_publish');
    expect($vpn->refresh()->status)->toBe(LifecycleStatus::Provisioning);
});

it('omits executor network projections from enrollment removal and restore plans', function (): void {
    $source = FleetFixtures::node('executor-plan-source');
    $services = FleetFixtures::node('executor-plan-services');
    $first = FleetFixtures::node('executor-plan-worker-one');
    $second = FleetFixtures::node('executor-plan-worker-two');
    $ordinary = FleetFixtures::node('executor-plan-ordinary');
    config(['dependency_detection.services_node_id' => $services->id, 'operation_executor.worker_node_ids' => [$first->id, $second->id]]);
    $plans = [
        app(NodeEnrollmentPlan::class)->build($source, ['mode' => 'linux', 'roleless_operator' => true],
            ['route_changes' => [], 'roles' => [], 'settings_provided' => false, 'task_vm_id' => null]),
        app(NodeRemovalPlan::class)->build($source, true),
        app(NodeRemovalPlan::class)->restore($source),
    ];

    foreach ($plans as $plan) {
        $targets = array_column(array_values(array_filter($plan, static fn (array $step): bool => ($step['kind'] ?? null) === 'node.network_projection')), 'node_id');
        expect($targets)->not->toContain($services->id, $first->id, $second->id);
        expect($targets)->toContain($ordinary->id);
    }
});

it('selects only active VPN owners for removal and restoration DNS children', function (bool $restore): void {
    $source = FleetFixtures::node('dns-removal-source');
    $vpn = FleetFixtures::node('dns-removal-vpn', [RoleName::Vpn]);
    $gateway = FleetFixtures::node('dns-removal-gateway', [RoleName::Gateway]);
    $pending = FleetFixtures::node('dns-removal-pending-vpn', [RoleName::Vpn]);
    $pending->roles()->update(['status' => LifecycleStatus::Provisioning]);
    $failed = FleetFixtures::node('dns-removal-failed-vpn', [RoleName::Vpn], status: LifecycleStatus::Failed);
    $planner = app(NodeRemovalPlan::class);
    $plan = $restore ? $planner->restore($source) : $planner->build($source, true);
    $dns = array_values(array_filter($plan, static fn (array $step): bool => ($step['kind'] ?? null) === 'route.private_dns_publish'));

    expect(array_column($dns, 'node_id'))->toBe([$vpn->id]);
    expect(array_column($dns, 'node_id'))->not->toContain($gateway->id, $pending->id, $failed->id);
})->with([false, true]);
