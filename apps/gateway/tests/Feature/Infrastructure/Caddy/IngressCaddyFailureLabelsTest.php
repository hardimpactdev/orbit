<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Cluster;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\FakeNodeConverger;

beforeEach(function (): void {
    $this->caller = $this->markAsGateway(caddy_failure_node('gateway-peer', '10.44.0.1'));
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    caddy_failure_bind();
    app()->instance(NodeConverger::class, new FakeNodeConverger);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
});

it('names ingress and ingress.caddy_config_failed when ingress convergence cannot order Caddy', function (): void {
    caddy_failure_bind('ordering');
    $cluster = Cluster::query()->create(['name' => 'ingress-ordering']);
    $node = caddy_failure_node('edge', '10.44.0.2', $cluster);

    $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'ingress'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.convergence_failed')
        ->assertJsonPath('error.message', 'Ingress step [caddy-service-ordering] failed on node [edge].')
        ->assertJsonPath('error.details.step', 'converge:caddy-service-ordering')
        ->assertJsonPath('error.details.error_code', 'ingress.caddy_config_failed')
        ->assertJsonMissingPath('error.details.message');

    $assignment = $node->roles()->where('role', RoleName::Ingress)->sole();

    expect($assignment->status)->toBe(LifecycleStatus::Failed)
        ->and($assignment->failed_step)->toBe('converge:caddy-service-ordering')
        ->and($assignment->error_code)->toBe('ingress.caddy_config_failed');
});

it('keeps the Caddy build message and names ingress.caddy_config_failed when ingress convergence cannot build', function (): void {
    $cluster = Cluster::query()->create(['name' => 'ingress-build']);
    $node = caddy_failure_node('edge', '10.44.0.2', $cluster);
    caddy_failure_builds()->failNext($node->name, 'validate', 'Error: loading certificates');

    $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'ingress'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.convergence_failed')
        ->assertJsonPath('error.message', 'The Caddy build for Node [edge] failed at stage [validate]: Error: loading certificates')
        ->assertJsonPath('error.details.step', 'converge:caddy-config')
        ->assertJsonPath('error.details.error_code', 'ingress.caddy_config_failed')
        ->assertJsonPath('error.details.node', 'edge')
        ->assertJsonPath('error.details.stage', 'validate')
        ->assertJsonPath('error.details.message', 'Error: loading certificates');

    $assignment = $node->roles()->where('role', RoleName::Ingress)->sole();

    expect($assignment->failed_step)->toBe('converge:caddy-config')
        ->and($assignment->error_code)->toBe('ingress.caddy_config_failed');
});

it('returns details.error_code ingress.caddy_config_failed when ingress removal stops at remove:caddy-config', function (): void {
    $cluster = Cluster::query()->create(['name' => 'ingress-removal']);
    $node = caddy_failure_node('edge', '10.44.0.2', $cluster);
    $node->roles()->create([
        'role' => RoleName::Ingress,
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
    ]);
    caddy_failure_builds()->failNext($node->name, 'validate', 'Error: loading certificates');

    $this->deleteJson("/api/v1/nodes/{$node->id}/roles/ingress", ['force' => true])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.remove_failed')
        ->assertJsonPath(
            'error.message',
            'The Caddy build for Node [edge] failed at stage [validate]: Error: loading certificates Retry with --offline if node [edge] is unreachable.',
        )
        ->assertJsonPath('error.details.step', 'remove:caddy-config')
        ->assertJsonPath('error.details.error_code', 'ingress.caddy_config_failed')
        ->assertJsonPath('error.details.node', 'edge')
        ->assertJsonPath('error.details.stage', 'validate')
        ->assertJsonPath('error.details.message', 'Error: loading certificates');

    $assignment = $node->roles()->where('role', RoleName::Ingress)->sole();

    expect($assignment->status)->toBe(LifecycleStatus::Failed)
        ->and($assignment->failed_step)->toBe('remove:caddy-config')
        ->and($assignment->error_code)->toBe('ingress.caddy_config_failed');
});

it('admits Router intent without executing Caddy in the request', function (bool $remove): void {
    $cluster = Cluster::query()->create(['name' => 'router-admission']);
    $node = caddy_failure_node('router-a', '10.44.0.4', $cluster);
    if ($remove) {
        $node->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    }
    caddy_failure_bind('ordering');
    $response = $remove
        ? $this->deleteJson("/api/v1/clusters/{$cluster->id}/router", ['force' => true])
        : $this->putJson("/api/v1/clusters/{$cluster->id}/router/{$node->id}");
    $response->assertAccepted()->assertJsonPath('data.kind', $remove ? 'cluster.router_unset' : 'cluster.router_set')
        ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.error_code', null);
    expect(app(AgentCommandRunner::class)->commands())->not->toContain('caddy.order-after-vpn');
})->with([false, true]);

it('keeps an app-dev removal that stops in the Caddy build on app-dev.caddy_config_failed', function (): void {
    $node = caddy_failure_node('app-host', '10.44.0.5');
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    caddy_failure_builds()->failNext($node->name, 'validate', 'Error: adapting config');

    $this->deleteJson("/api/v1/nodes/{$node->id}/roles/app-dev", ['force' => true])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.remove_failed')
        ->assertJsonPath('error.message', 'The Caddy build for Node [app-host] failed at stage [validate]: Error: adapting config Retry with --offline if node [app-host] is unreachable.')
        ->assertJsonPath('error.details.step', 'remove:caddy-config')
        ->assertJsonPath('error.details.error_code', 'app-dev.caddy_config_failed')
        ->assertJsonPath('error.details.message', 'Error: adapting config');

    expect($node->roles()->where('role', RoleName::AppDev)->sole()->error_code)->toBe('app-dev.caddy_config_failed');
});

it('names an ingress prerequisite failure as an ingress step and keeps its own code', function (): void {
    caddy_failure_bind('source');
    $cluster = Cluster::query()->create(['name' => 'ingress-source']);
    $node = caddy_failure_node('edge', '10.44.0.2', $cluster);

    $this->postJson("/api/v1/nodes/{$node->id}/roles", ['role' => 'ingress'])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.convergence_failed')
        ->assertJsonPath('error.message', 'Ingress step [caddy-package-source] failed on node [edge].')
        ->assertJsonPath('error.details.step', 'converge:caddy-package-source')
        ->assertJsonPath('error.details.error_code', 'ingress.prerequisite_failed');
});

function caddy_failure_node(string $name, string $ip, ?Cluster $cluster = null): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $ip,
        'cluster_id' => $cluster?->id,
    ]);
}

function caddy_failure_builds(): FakeNodeCaddyBuilds
{
    $builds = app(NodeCaddyBuilds::class);
    expect($builds)->toBeInstanceOf(FakeNodeCaddyBuilds::class);

    return $builds;
}

/** Binds an agent that answers the role prerequisites, refusing the Caddy package for `source`. */
function caddy_failure_bind(?string $fail = null): void
{
    $agents = (new FakeAgentCommandRunner)->answer('role.install-prerequisites', [])->answer('node.os-release', ['id' => 'ubuntu', 'codename' => 'resolute']);
    $fail === 'source'
        ? $agents->refuse('caddy.install-package', 'caddy.package_failed', ['reason' => 'forced failure'])
        : $agents->answer('caddy.install-package', ['changed' => false, 'version' => '2.10.2']);
    $fail === 'ordering'
        ? $agents->refuse('caddy.order-after-vpn', 'service.ordering_failed', ['stage' => 'restart'])
        : $agents->answer('caddy.order-after-vpn', ['changed' => false]);
    app()->instance(AgentCommandRunner::class, $agents);
}
