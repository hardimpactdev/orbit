<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Nodes\NodeRolePublicationInput;
use App\Domain\Nodes\NodeRoleRelocateProjection;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process as ManagedProcess;
use App\Models\Route;
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
        // The role child serves the sites; private DNS is the relocation's own step, after the probes and the certificate check.
        ->and(collect($intent['publications'])->pluck('node_id')->all())->toBe([$vpn->id])
        ->and(collect($intent['publications'])->every(static fn (array $p): bool => $p['after']['caddy'] === null && $p['after']['dns'] !== null))->toBeTrue()
        ->and(collect($ingress['publications'])->every(static fn (array $p): bool => $p['after']['dns'] === null))->toBeTrue()
        // The source keeps its own Caddyfile until its removal, even though it is the Router the Ingress change republishes.
        ->and(collect($ingress['publications'])->pluck('node_id')->all())->not->toContain($source->id)
        ->and($intent['process_probes'])->toBe([])
        ->and($intent['public_certificates'])->toBe([])
        ->and($intent['firewall'])->toBe(['desired' => [], 'remove' => []])
        ->and($intent['certificates'])->toBe(['leaves' => [], 'withdrawals' => []])
        ->and($ingress['role'])->toBe('ingress')
        ->and($ingress['node_id'])->toBe($target->id)
        ->and($ingress['caddy_package'])->not->toBeNull()
        ->and($ingress['exporters'])->toBe([])
        ->and($ingress['recorded_digests'])->toBe([])
        ->and(collect($ingress['files'])->firstWhere('path', NativeNodeRoleAddInput::OrbitRootPath))
        ->toMatchArray(['content' => "fixture-root-certificate\n", 'owner' => 'root', 'mode' => '0644'])
        // Clients holding the old private DNS answer get the grace.
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

/** A live public Process Route served from a Process on the source Router, which both Nodes reach on their LAN. */
function ingressRelocationProcessRoute(Node $source, Node $target, bool $admitTarget): Route
{
    ingressRelocationGateway();
    $source->update(['lan_ip' => '192.168.1.4']);
    $target->update(['lan_ip' => '192.168.1.41']);
    $process = ManagedProcess::query()->create(['owner_type' => Node::class, 'owner_id' => $source->id, 'name' => 'sync', 'runtime' => 'docker',
        'runtime_config' => ['image' => 'nginx:alpine', 'ports' => ['192.168.1.4:18080:8080/tcp']], 'desired_state' => 'running', 'status' => 'active']);
    $route = Route::query()->create(['kind' => 'custom_proxy', 'cluster_id' => $source->cluster_id, 'provenance' => 'explicit', 'publication' => 'public',
        'domain' => 'sync.example.com', 'status' => 'pending']);
    $route->customProxy()->create(['node_id' => $source->id, 'process_id' => $process->id, 'upstream' => 'http://192.168.1.4:18080']);
    $route->update(['status' => 'active', 'sites_published' => true, 'replacement_step' => 'cleanup']);
    if ($admitTarget) {
        $source->firewallRules()->create(['action' => 'allow', 'port' => 18080, 'protocol' => 'tcp', 'source' => '192.168.1.41', 'status' => LifecycleStatus::Active]);
    }

    return $route;
}

it('probes each public Process backend from the target and checks its public certificate before private DNS switches', function (): void {
    Http::preventStrayRequests();
    fakeIngressRelocationDispatch();
    ['source' => $source, 'target' => $target] = ingressRelocationFleet();
    ingressRelocationProcessRoute($source, $target, admitTarget: true);

    $intent = app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::Ingress, true)->refresh()->input['intent'];

    expect($intent['process_probes'])->toBe([['domain' => 'sync.example.com', 'host' => '192.168.1.4', 'port' => 18080]])
        ->and($intent['public_certificates'])->toBe(['sync.example.com']);
});

it('refuses an Ingress move when a Process Node firewall does not admit the target, before it records anything', function (): void {
    Http::preventStrayRequests();
    fakeIngressRelocationDispatch();
    ['source' => $source, 'target' => $target, 'assignment' => $assignment] = ingressRelocationFleet();
    ingressRelocationProcessRoute($source, $target, admitTarget: false);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::Ingress, true))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('route.upstream_firewall_mismatch'));

    expect(Operation::query()->count())->toBe(0)
        ->and($assignment->refresh()->node_id)->toBe($source->id);
});

it('republishes the Cluster Router when the Ingress changes, because the Router site trusts by where the Ingress runs', function (): void {
    ['source' => $source, 'target' => $target] = ingressRelocationFleet();

    expect(app(NodeRolePublicationInput::class)->capture($target, RoleName::Ingress)['caddy_node_ids'])->toBe([$source->id, $target->id]);
});

it('lets an Ingress site trust the system roots, so its Router hop holds during and after a move', function (): void {
    $site = new DevelopmentSite(nodeId: 2, nodeAddress: '10.44.0.41', scope: 'route-1-ingress', checkoutPath: '', documentRoot: '', phpVersion: null,
        domain: 'shop.example.com', upstreamAddresses: ['10.44.0.4:443'], publicListener: true, preserveForwardedIdentity: true, upstreamSystemRoots: true);
    $caddy = app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site]));
    $repository = new ReflectionMethod(DevelopmentSiteRepository::class, 'ingressSite');
    $source = file_get_contents($repository->getFileName()) ?: '';

    expect($caddy)->toContain('tls_server_name shop.example.com')->not->toContain('tls_trusted_ca_certs')
        ->and($source)->toContain('upstreamSystemRoots: $upstream !== []');
});

function ingressRelocationGateway(): void
{
    ingressRelocationNode('gateway', '10.44.0.2', null)->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
}

/** Settle a relocation run with the given failed step, as the shared run reader reports it. @param array<string,string> $steps */
function settleIngressRelocation(Operation $operation, array $steps, string $failed): void
{
    $operation->forceFill(['status' => 'running', 'state' => [...($operation->state ?? []), 'swamp_run_id' => 'run-relocate']])->save();
    app(NodeRoleRelocateProjection::class)->apply($operation->refresh(), ['run_id' => 'run-relocate', 'status' => 'failed', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'error_code' => 'operation.native_failed',
        'result' => ['steps' => array_map(static fn (string $status): array => ['status' => $status], $steps), 'summary' => ['failed_steps' => [$failed]]]]);
}

it('gives the Ingress back to the source when the move fails before private DNS switches', function (): void {
    Http::preventStrayRequests();
    fakeIngressRelocationDispatch();
    ['source' => $source, 'target' => $target, 'assignment' => $assignment] = ingressRelocationFleet();
    ingressRelocationGateway();
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::Ingress, true);

    settleIngressRelocation($operation, ['Probe the Process backends-0' => 'succeeded', 'Apply the Ingress role' => 'failed', 'Publish the role DNS-0' => 'skipped'], 'Apply the Ingress role');

    expect($assignment->refresh()->node_id)->toBe($source->id)
        ->and($assignment->status)->toBe(LifecycleStatus::Active)
        ->and($assignment->failed_step)->toBeNull()
        ->and($operation->refresh()->result)->toMatchArray(['failed_step' => 'Apply the Ingress role', 'source_kept' => true])
        ->and(app(PublicRouteEligibility::class)->servingIngress($source->cluster)?->id)->toBe($source->id);
});

it('keeps the Ingress on the target once private DNS has switched', function (): void {
    Http::preventStrayRequests();
    fakeIngressRelocationDispatch();
    ['target' => $target, 'assignment' => $assignment] = ingressRelocationFleet();
    ingressRelocationGateway();
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::Ingress, true);

    settleIngressRelocation($operation, ['Publish the role DNS-0' => 'succeeded', 'Remove from the source' => 'failed'], 'Remove from the source');

    expect($assignment->refresh()->node_id)->toBe($target->id)
        ->and($assignment->failed_step)->toBe('remove:Remove from the source')
        ->and($operation->refresh()->result)->not->toHaveKey('source_kept');
});
