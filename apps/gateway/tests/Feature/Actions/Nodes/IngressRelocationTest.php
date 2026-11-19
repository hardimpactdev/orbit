<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Nodes\NodeRolePublicationInput;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn("fixture-root-certificate\n");
});

function ingressRelocationNode(string $name, string $address, ?int $clusterId): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $address, 'public_ssh_host' => $address, 'user' => 'orbit', 'cluster_id' => $clusterId,
        'ssh_host_fingerprint' => 'SHA256:'.$name, 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $name.'-public-key',
    ]));
}

/** A production Cluster whose one Node holds the Router and the Ingress, a vpn Node, and an empty Cluster member. @return array{source:Node,target:Node,vpn:Node,cluster:Cluster,assignment:NodeRole} */
function ingressRelocationFleet(): array
{
    SwampNodeFixture::ensure();
    $cluster = Cluster::query()->create(['name' => 'prod', 'tld' => 'prod']);
    $cluster->forceFill(['state' => ClusterState::Active])->save();
    $vpn = ingressRelocationNode('vpn', '10.44.0.1', null);
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $source = ingressRelocationNode('edge-old', '10.44.0.4', $cluster->id);
    $source->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $assignment = $source->roles()->create(['role' => RoleName::Ingress, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $target = ingressRelocationNode('edge-new', '10.44.0.41', $cluster->id);

    return compact('source', 'target', 'vpn', 'cluster', 'assignment');
}

function fakeIngressRelocationDispatch(): object
{
    $dispatch = new class
    {
        /** @var list<int> */
        public array $started = [];

        public function start(Operation $operation): void
        {
            $this->started[] = $operation->id;
        }
    };
    app()->instance(SwampDispatch::class, $dispatch);

    return $dispatch;
}

it('makes the ingress role relocatable and keeps swamp held', function (): void {
    expect(app(RoleRegistry::class)->definition(RoleName::Ingress)->relocatable)->toBeTrue()
        ->and(app(RoleRegistry::class)->definition(RoleName::Swamp)->relocatable)->toBeFalse();
});

it('moves the Cluster Ingress to a member and gives the target the Ingress role child', function (): void {
    Http::preventStrayRequests();
    $dispatch = fakeIngressRelocationDispatch();
    ['source' => $source, 'target' => $target, 'vpn' => $vpn, 'assignment' => $assignment] = ingressRelocationFleet();
    ingressRelocationNode('gateway', '10.44.0.2', null)->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::Ingress, true);

    $intent = $operation->refresh()->input['intent'];
    $ingress = $intent['ingress'];
    expect($operation->kind)->toBe('node.role_relocate')
        ->and($dispatch->started)->toBe([$operation->id])
        ->and($assignment->refresh()->node_id)->toBe($target->id)
        ->and($assignment->cluster_id)->toBe($target->cluster_id)
        ->and($intent['role'])->toBe('ingress')
        ->and($intent['source_node_id'])->toBe($source->id)
        // The role child owns every target effect, so the relocation's own steps have nothing to do.
        ->and($intent['publications'])->toBe([])
        ->and($intent['firewall'])->toBe(['desired' => [], 'remove' => []])
        ->and($intent['certificates'])->toBe(['leaves' => [], 'withdrawals' => []])
        ->and($ingress['role'])->toBe('ingress')
        ->and($ingress['node_id'])->toBe($target->id)
        ->and($ingress['caddy_package'])->not->toBeNull()
        ->and($ingress['exporters'])->toBe([])
        ->and($ingress['recorded_digests'])->toBe([])
        ->and(collect($ingress['files'])->firstWhere('path', NativeNodeRoleAddInput::OrbitRootPath))
        ->toMatchArray(['content' => "fixture-root-certificate\n", 'owner' => 'root', 'mode' => '0644'])
        // Private DNS on the vpn Node follows the new Ingress; clients holding the old answer get the grace.
        ->and(collect($ingress['publications'])->firstWhere('node_id', $vpn->id)['after']['dns'])->not->toBeNull()
        ->and($intent['grace'])->not->toBe([])
        // The source drops its public sites as node:role:remove builds it.
        ->and($intent['source']['node_id'])->toBe($source->id)
        ->and($intent['source']['role'])->toBe('ingress')
        ->and(collect($intent['source']['publications'])->pluck('node_id')->all())->toContain($source->id);
});

it('refuses an Ingress move to a Node outside the Cluster before it records anything', function (): void {
    Http::preventStrayRequests();
    fakeIngressRelocationDispatch();
    ['assignment' => $assignment] = ingressRelocationFleet();
    $outside = ingressRelocationNode('outside', '10.44.0.50', null);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->relocate($outside, RoleName::Ingress, true))
        ->toThrow(NodeRoleValidationException::class);

    expect(Operation::query()->count())->toBe(0)
        ->and($assignment->refresh()->node_id)->not->toBe($outside->id);
});

it('refuses an Ingress move to a Node of another Cluster that has no Ingress', function (): void {
    Http::preventStrayRequests();
    fakeIngressRelocationDispatch();
    ingressRelocationFleet();
    $other = Cluster::query()->create(['name' => 'other', 'tld' => 'other']);
    $member = ingressRelocationNode('other-member', '10.44.0.51', $other->id);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->relocate($member, RoleName::Ingress, true))
        ->toThrow(NodeRoleValidationException::class);

    expect(Operation::query()->count())->toBe(0);
});

it('refuses an Ingress move onto a Node with a conflicting role', function (RoleName $role): void {
    Http::preventStrayRequests();
    fakeIngressRelocationDispatch();
    ['target' => $target, 'assignment' => $assignment, 'source' => $source] = ingressRelocationFleet();
    $target->roles()->create(['role' => $role, 'status' => 'active']);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::Ingress, true))
        ->toThrow(RoleAssignmentException::class);

    expect(Operation::query()->count())->toBe(0)
        ->and($assignment->refresh()->node_id)->toBe($source->id)
        ->and($assignment->status)->toBe(LifecycleStatus::Active);
})->with(['gateway' => RoleName::Gateway, 'app-dev' => RoleName::AppDev, 'database' => RoleName::Database]);

it('publishes private DNS when the Ingress role converges', function (): void {
    ['target' => $target, 'vpn' => $vpn] = ingressRelocationFleet();

    expect(app(NodeRolePublicationInput::class)->capture($target, RoleName::Ingress)['dns_node_ids'])->toBe([$vpn->id]);
});

it('installs the Orbit root with the Ingress role, and not with a role whose sites bring it', function (): void {
    ['target' => $target] = ingressRelocationFleet();

    $paths = static fn (RoleName $role): array => array_column(app(NativeNodeRoleAddInput::class)->nodeResources($target, $role)['files'], 'path');

    expect($paths(RoleName::Ingress))->toContain(NativeNodeRoleAddInput::OrbitRootPath)
        ->and($paths(RoleName::Router))->not->toContain(NativeNodeRoleAddInput::OrbitRootPath);
});
