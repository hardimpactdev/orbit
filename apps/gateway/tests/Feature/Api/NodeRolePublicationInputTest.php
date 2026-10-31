<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRolePublicationInput;
use App\Domain\Nodes\RoleName;
use App\Models\Operation;
use App\Models\Route;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
});

it('keeps whole Node PHP publication even when an app role has no Routes', function (): void {
    $node = FleetFixtures::node('publication-app', [RoleName::AppProd]);
    $plan = app(NodeRolePublicationInput::class)->capture($node, RoleName::AppProd);
    expect($plan['php_node_ids'])->toBe([$node->id])
        ->and($plan['caddy_node_ids'])->toBe([$node->id]);
});

it('captures true DNS placements and both Gateway transfer Caddy endpoints plus Reverb', function (): void {
    $source = FleetFixtures::node('publication-old-gateway', [RoleName::Gateway]);
    $target = FleetFixtures::node('publication-new-gateway', []);
    $websocket = FleetFixtures::node('publication-reverb', [RoleName::WebSocket]);
    $vpn = FleetFixtures::node('publication-vpn', [RoleName::Vpn]);
    $unrelated = FleetFixtures::node('publication-unrelated', [RoleName::Database]);
    $plan = app(NodeRolePublicationInput::class)->capture($target, RoleName::Gateway, $source);
    $expected = [$source->id, $target->id, $websocket->id];
    sort($expected);
    expect($plan['caddy_node_ids'])->toBe($expected)->and($plan['dns_node_ids'])->toBe([$vpn->id])
        ->and(NodeRolePublicationInput::participants($plan))->not->toContain($unrelated->id)
        ->and($plan['php_node_ids'])->toBe([]);
});

it('never withdraws unrelated Route rows when publishing a role change', function (): void {
    $node = FleetFixtures::node('publication-route-owner', [RoleName::AppProd]);
    $route = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $node->id,
        'domain' => 'survives-role.private.test', 'publication' => 'private', 'provenance' => 'explicit',
        'status' => 'pending', 'sites_published' => false]);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:8787']);
    $route->update(['status' => 'active', 'sites_published' => true]);
    app(NodeRolePublicationInput::class)->capture($node, RoleName::AppProd);
    expect($route->fresh()->sites_published)->toBeTrue()->and($route->fresh()->status->value)->toBe('active');
});

it('returns only the authenticated DNS participant public artifacts', function (): void {
    $root = FleetFixtures::node('publication-metrics', [RoleName::Metrics]);
    $vpn = FleetFixtures::node('publication-dns', [RoleName::Vpn]);
    $plan = app(NodeRolePublicationInput::class)->capture($root, RoleName::Metrics);
    $operation = new Operation(['kind' => 'node.role_add', 'node_id' => $root->id,
        'target_type' => 'node', 'target_id' => $root->id, 'status' => 'running',
        'input' => ['participant_node_ids' => [$root->id, $vpn->id], '_role' => ['publication' => $plan],
            'runtime_material' => ['$secret' => 'node.role_runtime', 'id' => $root->id]]]);
    $input = app(NodeRolePublicationInput::class)->publicInput($operation, $vpn->id);
    expect(array_keys($input))->toBe(['node_id', 'private_dns_publication'])
        ->and($input['private_dns_publication']['node_id'])->toBe($vpn->id)
        ->and(json_encode($input))->not->toContain('runtime_material', '$secret');
});

it('gives an admitted runtime participant only its public cleanup plan without publication artifacts', function (): void {
    $root = FleetFixtures::node('publication-runtime', [RoleName::Metrics]);
    $foreign = FleetFixtures::node('publication-no-duty', [RoleName::Database]);
    $plan = app(NodeRolePublicationInput::class)->capture($root, RoleName::Metrics);
    $operation = new Operation(['kind' => 'node.role_remove', 'node_id' => $root->id,
        'target_type' => 'node', 'target_id' => $root->id, 'status' => 'running',
        'input' => ['participant_node_ids' => [$root->id, $foreign->id], '_role_remove' => ['publication' => $plan]]]);
    $input = app(NodeRolePublicationInput::class)->publicInput($operation, $foreign->id);
    expect($input)->toBe(['node_id' => $foreign->id, 'role_removal' => ['publication' => $plan]]);
    expect($input)->not->toHaveKeys(['caddy_publication', 'php_publication', 'private_dns_publication', 'runtime_material']);
});

it('refuses a role root with no immutable publication plan instead of treating it as empty', function (): void {
    $root = FleetFixtures::node('publication-unbound', [RoleName::Metrics]);
    $operation = new Operation(['kind' => 'node.role_add', 'node_id' => $root->id,
        'target_type' => 'node', 'target_id' => $root->id, 'status' => 'running',
        'input' => ['participant_node_ids' => [$root->id], '_role' => []]]);
    expect(fn () => app(NodeRolePublicationInput::class)->publicInput($operation, $root->id))
        ->toThrow(InvalidArgumentException::class);
});
