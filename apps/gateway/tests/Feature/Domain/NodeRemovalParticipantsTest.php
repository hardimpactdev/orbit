<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeNetworkParticipantInput;
use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalParticipants;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalPublicInput;
use App\Domain\Nodes\RoleName;
use App\Models\Cluster;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteRemovalResidue;
use Tests\Support\Fleet\FleetFixtures;

it('omits unrelated roleless TaskVMs without enrolling workers or changing their records', function (): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('participant-unrelated-gateway', [RoleName::Gateway]);
    $guest = FleetFixtures::taskVm();
    $unrelated = FleetFixtures::taskVm();
    $unrelated->roles()->delete();
    $unrelated->update(['wireguard_public_key' => base64_encode(str_repeat('u', 32))]);
    $before = $unrelated->fresh()->getAttributes();
    config(['swamp_workers.nodes' => []]);

    $participants = app(NodeRemovalParticipants::class)->capture($guest);

    $input = app(NodeRemovalInput::class)->capture(app(NodeRemovalPlan::class)->build($guest, true, true), $root);

    expect($participants)->toBe([]);
    expect($input['unavailable_node_ids'])->toBe([]);
    expect(config('swamp_workers.nodes'))->toBe([]);
    expect($unrelated->fresh()->getAttributes())->toBe($before);
});

it('retains global peer DNS contributors without a Node access edge and without a trusted worker', function (): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('participant-gateway', [RoleName::Gateway]);
    $guest = FleetFixtures::node('participant-departing');
    $peer = FleetFixtures::node('participant-peer', [RoleName::AppDev]);
    $peer->update(['wireguard_public_key' => base64_encode(str_repeat('p', 32))]);
    config(['swamp_workers.nodes' => [$root->id]]);

    $capture = app(NodeRemovalInput::class)->capture(app(NodeRemovalPlan::class)->build($guest, true, true), $root);

    expect($capture['snapshot']['participants'])->toBe([
        ['node_id' => $peer->id, 'peer' => true, 'routes' => false, 'residue' => false, 'dns' => false],
    ]);
    expect($capture['unavailable_node_ids'])->toContain($peer->id);
    $this->assertDatabaseCount('node_access', 0);
});

it('retains same-cluster Router LAN work independently of peer DNS and access edges', function (): void {
    FleetFixtures::bind();
    $cluster = Cluster::query()->create(['name' => 'removal-participant-cluster']);
    $guest = FleetFixtures::node('participant-router-context');
    $guest->update(['cluster_id' => $cluster->id]);
    $router = FleetFixtures::node('participant-router');
    $router->update(['cluster_id' => $cluster->id, 'lan_ip' => '192.168.70.1', 'wireguard_public_key' => null]);
    NodeRole::query()->create(['node_id' => $router->id, 'cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => 'active']);
    $otherCluster = Cluster::query()->create(['name' => 'removal-unrelated-cluster']);
    $other = FleetFixtures::node('participant-other-router');
    $other->update(['cluster_id' => $otherCluster->id]);
    NodeRole::query()->create(['node_id' => $other->id, 'cluster_id' => $otherCluster->id, 'role' => RoleName::Router, 'status' => 'active']);
    $other->update(['lan_ip' => '192.168.80.1', 'wireguard_public_key' => null]);

    expect(app(NodeRemovalParticipants::class)->capture($guest))->toBe([
        ['node_id' => $router->id, 'peer' => true, 'routes' => false, 'residue' => false, 'dns' => false],
    ]);
    expect(app(NodeNetworkParticipantInput::class)->capture($router->fresh(), $guest, true)['router_lan'])->toBeArray();
});

it('retains independent Route DNS and stale residue owners including roleless TaskVMs', function (): void {
    FleetFixtures::bind();
    $guest = FleetFixtures::node('participant-required-context');
    $route = FleetFixtures::taskVm();
    $route->roles()->delete();
    $dns = FleetFixtures::node('participant-required-dns');
    $vpn = FleetFixtures::node('participant-required-vpn', [RoleName::Vpn]);
    $residue = FleetFixtures::node('participant-required-residue');
    RouteRemovalResidue::query()->create(['node_id' => $residue->id, 'route_id' => 987650, 'domain' => 'gone.orbit', 'steps' => ['caddy']]);
    $canonical = ['nodes' => [['node_id' => $route->id], ['node_id' => $dns->id], ['node_id' => $guest->id]],
        'dns_node_ids' => [$dns->id, $vpn->id, $guest->id]];

    $participants = app(NodeRemovalParticipants::class)->capture($guest, $canonical);

    expect($participants)->toBe([
        ['node_id' => $route->id, 'peer' => false, 'routes' => true, 'residue' => false, 'dns' => false],
        ['node_id' => $dns->id, 'peer' => false, 'routes' => true, 'residue' => false, 'dns' => true],
        ['node_id' => $vpn->id, 'peer' => false, 'routes' => false, 'residue' => false, 'dns' => true],
        ['node_id' => $residue->id, 'peer' => false, 'routes' => false, 'residue' => true, 'dns' => false],
    ]);
    expect(array_column(app(NodeRemovalParticipants::class)->capture($guest, $canonical, excludeContext: false), 'node_id'))->toContain($guest->id);
});

it('does not select a residue owner while the associated Route still exists', function (): void {
    $guest = FleetFixtures::node('participant-residue-context');
    $owner = FleetFixtures::node('participant-residue-owner');
    $project = Project::query()->create(['slug' => 'participant-residue', 'name' => 'Residue', 'repository_url' => 'https://github.com/example/residue.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $owner->id, 'domain' => 'kept.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    RouteRemovalResidue::query()->create(['node_id' => $owner->id, 'route_id' => $route->id, 'domain' => $route->domain, 'steps' => ['caddy']]);

    expect(app(NodeRemovalParticipants::class)->capture($guest))->toBe([]);
});

it('returns only admitted effect categories in fresh participant reads', function (): void {
    FleetFixtures::bind();
    $guest = FleetFixtures::node('participant-bounded-context');
    $owner = FleetFixtures::node('participant-bounded-owner', [RoleName::AppDev]);
    $owner->update(['wireguard_public_key' => base64_encode(str_repeat('b', 32))]);
    RouteRemovalResidue::query()->create(['node_id' => $owner->id, 'route_id' => 987651, 'domain' => 'gone.orbit', 'steps' => ['caddy']]);
    $snapshot = app(NodeRemovalPlan::class)->build($guest, true, true);
    $snapshot['participants'] = [['node_id' => $owner->id, 'peer' => false, 'routes' => true, 'residue' => false, 'dns' => false]];

    $view = app(NodeRemovalPublicInput::class)->participant($snapshot, $owner);

    expect($view['network'])->toBeNull()->and($view['residue'])->toBeNull()->and($view['dns'])->toBeNull();
});
