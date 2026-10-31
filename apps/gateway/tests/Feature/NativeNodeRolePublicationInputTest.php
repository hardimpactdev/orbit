<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeNodeRolePublicationInput;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Cluster;
use App\Models\Instance;
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
