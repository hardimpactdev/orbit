<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeNodeRolePublicationInput;
use App\Domain\Routes\RoutePublicationSites;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyListeners;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Setting;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $manifest = tempnam(sys_get_temp_dir(), 'role-publication-agent-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->gateway = FleetFixtures::node('role-gateway', [RoleName::Gateway, RoleName::Vpn]);
});

it('captures prospective role sites before certificate markers without writing state', function (RoleName $role, string $host): void {
    $node = FleetFixtures::node('role-target', [$role]);
    $nodeBefore = $node->fresh()->getAttributes();
    $rolesBefore = $node->roles()->get()->toArray();
    $settingsBefore = Setting::query()->get()->toArray();
    $capture = app(NativeNodeRolePublicationInput::class)->capture($node, $role);
    $publicationNode = $role === RoleName::Metrics ? $this->gateway : $node;
    $row = collect($capture['publications'])->firstWhere('node_id', $publicationNode->id);
    expect($row['before']['caddy']['content'])->not->toContain('# orbit: '.$role->value.' '.$host)
        ->and($row['after']['caddy']['content'])->toContain('# orbit: '.$role->value.' '.$host)
        ->and($row['after'])->toHaveKeys(['caddy', 'php', 'dns', 'certificate_scopes'])
        ->and($row['connection']['nodeHost'])->toBe($publicationNode->wireguard_ip)
        ->and($row['control_node_id'])->toBe(209)
        ->and($row['host_keys'])->not->toBeEmpty()
        ->and($capture['certificates']['leaves'])->toHaveCount(1)
        ->and($capture['participant_node_ids'])->toContain($node->id, $this->gateway->id, 209)
        ->and($node->fresh()->getAttributes())->toBe($nodeBefore)
        ->and($node->roles()->get()->toArray())->toBe($rolesBefore)
        ->and(Setting::query()->get()->toArray())->toBe($settingsBefore)
        ->and(Operation::query()->count())->toBe(0)
        ->and(app(CaddySiteCertificates::class)->published($publicationNode->id, $role->value))->toBeFalse();
})->with([
    'websocket' => [RoleName::WebSocket, 'reverb.orbit'],
    'analytics' => [RoleName::Analytics, 'analytics.orbit'],
    'metrics' => [RoleName::Metrics, 'metrics.orbit'],
]);

it('captures prospective websocket DNS while retaining actual old target in before', function (): void {
    $old = FleetFixtures::node('old-websocket');
    app(CaddySiteCertificates::class)->record($old->id, 'websocket');
    $node = FleetFixtures::node('new-websocket', [RoleName::WebSocket]);
    $capture = app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::WebSocket);
    $row = collect($capture['publications'])->firstWhere('node_id', $this->gateway->id);
    expect($row['before']['dns']['records'])->toContain('host-record=reverb.orbit,'.$old->wireguard_ip)
        ->and($row['after']['dns']['records'])->toContain('host-record=reverb.orbit,'.$node->wireguard_ip)
        ->and(app(WebSocketDnsTarget::class)->node($node)?->id)->toBe($old->id)
        ->and(app(WebSocketDnsTarget::class)->isServing($node->id))->toBeFalse();
});

it('restores planned certificate readiness after nested renders and exceptions', function (): void {
    $node = FleetFixtures::node('render-context');
    $context = app(RouteRenderContext::class);
    $certificates = app(CaddySiteCertificates::class);
    $outer = ['leaves' => [['node_id' => $node->id, 'scope' => 'role-analytics']], 'withdrawals' => []];
    $inner = ['leaves' => [], 'withdrawals' => [['node_id' => $node->id, 'scope' => 'role-analytics']]];
    $context->renderRoleCertificates($outer, function () use ($node, $context, $certificates, $inner): void {
        expect($certificates->published($node->id, 'analytics'))->toBeTrue()
            ->and($certificates->published($this->gateway->id, 'analytics'))->toBeFalse();
        expect(fn () => $context->renderRoleCertificates($inner, function () use ($node, $certificates): never {
            expect($certificates->published($node->id, 'analytics'))->toBeFalse();
            throw new RuntimeException('render failure');
        }))->toThrow(RuntimeException::class, 'render failure');
        expect($certificates->published($node->id, 'analytics'))->toBeTrue();
    });
    expect($certificates->published($node->id, 'analytics'))->toBeFalse();
});

it('rejects capture without an existing serving assignment', function (): void {
    $node = FleetFixtures::node('missing-role');
    expect(fn () => app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::Analytics))
        ->toThrow(ResourceOperationException::class);
});

it('keeps complete app sites and PHP pools in both snapshots', function (): void {
    $node = FleetFixtures::node('role-app', [RoleName::AppDev]);
    $project = Project::query()->create(['name' => 'Survivor', 'slug' => 'survivor', 'repository_url' => 'https://example.test/survivor.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'default', 'checkout_path' => '/srv/survivor', 'root' => 'public', 'selected_php_version' => '8.5', 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'domain' => 'survivor.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $capture = app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::AppDev);
    $row = collect($capture['publications'])->firstWhere('node_id', $node->id);
    expect($row['before']['caddy']['content'])->toContain('survivor.private.test')
        ->and($row['after']['caddy'])->toBe($row['before']['caddy'])
        ->and((array) $row['after']['php']['configurations'])->not->toBeEmpty()
        ->and($row['after']['php'])->toEqual($row['before']['php']);
    $dns = collect($capture['publications'])->firstWhere('node_id', $this->gateway->id);
    expect($dns['after']['caddy'])->toBeNull()->and($dns['after']['php'])->toBeNull()
        ->and($dns['after']['dns']['records'])->toContain('survivor.private.test');
    $immutable = json_encode($capture, JSON_THROW_ON_ERROR);
    $node->update(['wireguard_ip' => '10.44.0.199']);
    expect(json_encode($capture, JSON_THROW_ON_ERROR))->toBe($immutable);
});

it('captures ordinary role duties without manufacturing certificates', function (RoleName $role): void {
    $node = FleetFixtures::node('ordinary-role');
    $clusterId = null;
    if (in_array($role, [RoleName::Router, RoleName::Ingress], true)) {
        $cluster = Cluster::query()->create(['name' => 'ordinary-cluster', 'state' => 'active']);
        $node->update(['cluster_id' => $cluster->id]);
        $clusterId = $cluster->id;
    }
    $node->roles()->create(['role' => $role, 'status' => 'active', 'cluster_id' => $clusterId]);
    $capture = app(NativeNodeRolePublicationInput::class)->capture($node, $role);
    expect($capture['certificates'])->toBe(['leaves' => [], 'withdrawals' => []])
        ->and($capture['participant_node_ids'])->toContain($node->id, 209);
    if ($role === RoleName::Database) {
        expect($capture['publications'])->toBe([]);
    }
})->with([RoleName::Gateway, RoleName::Router, RoleName::Ingress, RoleName::AppDev, RoleName::AppProd, RoleName::Database]);

function rolePublicationCandidate(Node $node, array $values = []): NodeEnrollmentCandidate
{
    return new NodeEnrollmentCandidate(
        nodeId: $values['nodeId'] ?? $node->id,
        name: $values['name'] ?? 'next-role-target',
        user: $values['user'] ?? 'nextorbit',
        platform: 'linux', architecture: 'x86_64',
        wireguardIp: $values['wireguardIp'] ?? '10.44.0.201',
        publicSshHost: '192.0.2.201', publicSshPort: 2222,
        clusterId: $values['clusterId'] ?? null, tld: $values['tld'] ?? 'next.test',
        lanIp: $values['lanIp'] ?? '10.77.0.201',
        wireguardEndpointOverride: null, dnsServerOverride: null,
    );
}

it('captures prospective account and complete app sites without reusing the actual catalog', function (): void {
    $node = FleetFixtures::node('candidate-app', [RoleName::AppDev]);
    $node->update(['tld' => 'prior.test']);
    $project = Project::query()->create(['name' => 'Candidate App', 'slug' => 'candidate-app', 'repository_url' => 'https://example.test/candidate.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/srv/candidate', 'root' => 'public', 'selected_php_version' => '8.5', 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'literal.prior.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $stored = $node->fresh()->getAttributes();
    $roles = $node->roles()->get()->toArray();
    $settings = Setting::query()->get()->toArray();

    $capture = app(RoutePublicationSites::class)->render(collect(),
        fn () => app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::AppDev, rolePublicationCandidate($node)));
    $row = collect($capture['publications'])->firstWhere('node_id', $node->id);
    $dns = collect($capture['publications'])->firstWhere('node_id', $this->gateway->id);

    expect($row['before']['caddy']['content'])->toContain('bind '.$node->wireguard_ip)
        ->and($row['after']['caddy']['content'])->toContain('bind 10.44.0.201')->toContain('literal.prior.test')
        ->and($row['before']['php']['account_user'])->toBe('orbit')
        ->and($row['after']['php']['account_user'])->toBe('nextorbit')
        ->and(json_encode($row['after']['php']['configurations']))->toContain('user = nextorbit')
        ->and($row['connection']['nodeHost'])->toBe('10.44.0.201')
        ->and($row['connection']['nodeUser'])->toBe('nextorbit')
        ->and($row['host_keys'])->toBe([$node->ssh_host_key_type.' '.$node->ssh_host_key])
        ->and($dns['before']['dns']['records'])->toContain('address=/.prior.test/'.$node->wireguard_ip)
        ->and($dns['after']['dns']['records'])->toContain('address=/.next.test/10.44.0.201')->toContain('host-record=literal.prior.test,10.44.0.201')
        ->and($dns['after']['caddy'])->toBeNull()->and($dns['after']['php'])->toBeNull()
        ->and($dns['host_keys'])->not->toBeNull()
        ->and($node->fresh()->getAttributes())->toBe($stored)
        ->and($node->roles()->get()->toArray())->toBe($roles)
        ->and(Setting::query()->get()->toArray())->toBe($settings)
        ->and(Operation::query()->count())->toBe(0);
});

it('captures an explicitly pending same-node target with required native pin binding', function (): void {
    $node = FleetFixtures::node('pending-websocket', [RoleName::WebSocket]);
    $node->update(['status' => 'provisioning', 'wireguard_ip' => null, 'ssh_host_key_type' => null, 'ssh_host_key' => null]);
    $node->roles()->update(['status' => 'provisioning']);
    $stored = $node->fresh()->getAttributes();

    $capture = app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::WebSocket, rolePublicationCandidate($node));
    $row = collect($capture['publications'])->firstWhere('node_id', $node->id);
    $dns = collect($capture['publications'])->firstWhere('node_id', $this->gateway->id);

    expect($row['host_keys'])->toBeNull()
        ->and($row['control_node_id'])->toBe(209)
        ->and($row['connection'])->toBe(['nodeHost' => '10.44.0.201', 'nodeUser' => 'nextorbit', 'nodePort' => 22,
            'nodeIdentityFile' => '/run/credentials/orbit-node-worker.service/proxy-ssh-identity'])
        ->and($row['before']['caddy']['content'])->not->toContain('# orbit: websocket')
        ->and($row['after']['caddy']['content'])->toContain('# orbit: websocket reverb.orbit')->toContain('10.44.0.201')
        ->and($row['after']['php'])->toBeNull()
        ->and($dns['before']['dns']['records'])->not->toContain('host-record=reverb.orbit,10.44.0.201')
        ->and($dns['after']['dns']['records'])->toContain('host-record=reverb.orbit,10.44.0.201')
        ->and($node->fresh()->getAttributes())->toBe($stored);
});

it('rejects a different prospective Node identity before publication capture', function (): void {
    $node = FleetFixtures::node('candidate-identity', [RoleName::WebSocket]);
    expect(fn () => app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::WebSocket,
        rolePublicationCandidate($node, ['nodeId' => $this->gateway->id])))
        ->toThrow(ResourceOperationException::class);
});

it('keeps actual before outside nested scopes and restores every render context', function (): void {
    $node = FleetFixtures::node('nested-candidate', [RoleName::WebSocket]);
    $context = app(RouteRenderContext::class);
    $outer = rolePublicationCandidate($node, ['wireguardIp' => '10.44.0.202', 'name' => 'outer-target']);
    $plan = ['leaves' => [['node_id' => $node->id, 'scope' => 'role-websocket']], 'withdrawals' => []];

    $context->renderEnrollment($outer, function () use ($node, $context, $plan): void {
        $context->renderRoleCertificates($plan, function () use ($node, $context): void {
            $capture = app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::WebSocket, rolePublicationCandidate($node));
            $row = collect($capture['publications'])->firstWhere('node_id', $node->id);
            expect($row['before']['caddy']['content'])->not->toContain('# orbit: websocket')
                ->and($row['after']['caddy']['content'])->toContain('10.44.0.201')
                ->and($context->wireguardIp($node))->toBe('10.44.0.202')
                ->and($context->name($node))->toBe('outer-target')
                ->and(app(CaddySiteCertificates::class)->published($node->id, 'websocket'))->toBeTrue();
            expect(fn () => $context->renderEnrollment(rolePublicationCandidate($node), fn () => throw new RuntimeException('candidate error')))
                ->toThrow(RuntimeException::class, 'candidate error');
            expect($context->name($node))->toBe('outer-target');
        });
        expect(app(CaddySiteCertificates::class)->published($node->id, 'websocket'))->toBeFalse();
    });
    expect($context->nodeId())->toBeNull()->and($context->name($node))->toBe($node->name)
        ->and($context->user($node))->toBe($node->user)->and($context->lanIp($node))->toBe($node->lan_ip);
});

it('renders prospective Gateway hostname and LAN listeners through canonical readers', function (): void {
    $node = FleetFixtures::node('prior-gateway', [RoleName::Gateway]);
    $node->update(['lan_ip' => '10.77.0.101']);
    $capture = app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::Gateway, rolePublicationCandidate($node));
    $row = collect($capture['publications'])->firstWhere('node_id', $node->id);
    expect($row['before']['caddy']['content'])->toContain('prior-gateway.orbit')
        ->and($row['after']['caddy']['content'])->toContain('next-role-target.orbit')->not->toContain('prior-gateway.orbit');
    $listeners = app(RouteRenderContext::class)->renderEnrollment(rolePublicationCandidate($node),
        fn () => NodeCaddyListeners::forSites($node));
    expect($listeners->explicit)->toBe(['10.44.0.201', '10.77.0.201']);
});

it('uses candidate cluster membership and LAN in the DNS catalog while explicit overrides win', function (): void {
    $cluster = Cluster::query()->create(['name' => 'candidate-cluster', 'tld' => 'cluster.test', 'state' => 'active']);
    $router = FleetFixtures::node('candidate-router');
    $router->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.77.0.1']);
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $member = FleetFixtures::node('candidate-requester', [RoleName::AppDev]);
    $member->update(['wireguard_public_key' => 'public-requester', 'lan_ip' => null, 'cluster_id' => null]);
    $renderer = app(DevelopmentDnsConfigRenderer::class);
    $context = app(RouteRenderContext::class);
    $memberBefore = $member->fresh()->getAttributes();
    $candidate = rolePublicationCandidate($member, ['clusterId' => $cluster->id]);

    $catalog = $context->renderEnrollment($candidate, fn () => $renderer->catalog());
    $overridden = $context->renderEnrollment($candidate, fn () => $renderer->catalog(null, [$member->id => ['cluster_id' => null]]));
    expect($catalog->overrides['node:'.$member->id]['cluster.test'])->toBe('10.77.0.1')
        ->and($overridden->overrides)->not->toHaveKey('node:'.$member->id)
        ->and($renderer->catalog()->overrides)->not->toHaveKey('node:'.$member->id)
        ->and($member->fresh()->getAttributes())->toBe($memberBefore);

    $member->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.77.0.2']);
    $routerCandidate = rolePublicationCandidate($router, ['clusterId' => $cluster->id, 'lanIp' => '10.77.0.99']);
    $catalog = $context->renderEnrollment($routerCandidate, fn () => $renderer->catalog());
    $overridden = $context->renderEnrollment($routerCandidate, fn () => $renderer->catalog(null, [$router->id => ['lan_ip' => '10.77.0.88']]));
    expect($catalog->overrides['node:'.$member->id]['cluster.test'])->toBe('10.77.0.99')
        ->and($overridden->overrides['node:'.$member->id]['cluster.test'])->toBe('10.77.0.88');
});

it('restores outer identity and readiness when a publication participant cannot be captured', function (): void {
    $node = FleetFixtures::node('failing-candidate', [RoleName::WebSocket]);
    $this->gateway->update(['ssh_host_key' => null]);
    $context = app(RouteRenderContext::class);
    $outer = rolePublicationCandidate($node, ['name' => 'outer-failure']);
    $plan = ['leaves' => [['node_id' => $node->id, 'scope' => 'role-websocket']], 'withdrawals' => []];
    $context->renderEnrollment($outer, function () use ($node, $context, $plan): void {
        $context->renderRoleCertificates($plan, function () use ($node, $context): void {
            expect(fn () => app(NativeNodeRolePublicationInput::class)->capture($node, RoleName::WebSocket, rolePublicationCandidate($node)))
                ->toThrow(ResourceOperationException::class);
            expect($context->name($node))->toBe('outer-failure')
                ->and(app(CaddySiteCertificates::class)->published($node->id, 'websocket'))->toBeTrue();
        });
    });
    expect($context->nodeId())->toBeNull()
        ->and(app(CaddySiteCertificates::class)->published($node->id, 'websocket'))->toBeFalse();
});
