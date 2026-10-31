<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceState;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\GatewayPrivateDnsRoute;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Route as OrbitRoute;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGatewayPrivateDnsRoute;
use Tests\Support\FakeNodeConverger;
use Tests\Support\FakeNodeRoleFirewallManager;
use Tests\Support\FakeToolManagerMaterializer;
use Tests\TestCase;

beforeEach(function (): void {
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    app()->instance(NodeConverger::class, new FakeNodeConverger);
    $this->roleLifecycle = new NodeRoleApiLifecycleFake;
    app()->instance(RoleBaselineConverger::class, $this->roleLifecycle);
    app()->instance(NodeRoleDependentCleaner::class, $this->roleLifecycle);
    app()->instance(NodeRoleFirewallManager::class, new FakeNodeRoleFirewallManager);
    app()->instance(PrivateDnsManager::class, new class implements PrivateDnsManager
    {
        public function converge(?Node $pendingNode = null): void {}
    });
    $this->route = new FakeGatewayPrivateDnsRoute;
    app()->instance(GatewayPrivateDnsRoute::class, $this->route);
    $this->reachability = new NodeRoleApiReachabilityFake;
    app()->instance(NodeReachabilityProbe::class, $this->reachability);

    $this->caller = $this->markAsGateway(node_roles_api_node('gateway-peer'));
    $this->node = node_roles_api_node('role-target');
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
});

it('rejects direct target access for Metrics role mutation', function (): void {
    $target = node_roles_api_node('metrics-target');
    $direct = node_roles_api_node('direct-target-consumer');
    $direct->accessibleNodes()->attach($target);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $direct->wireguard_ip])
        ->postJson("/api/v1/nodes/{$target->id}/roles", ['role' => 'metrics'])
        ->assertForbidden();
});

it('rejects direct target access for Metrics role removal', function (): void {
    $target = node_roles_api_node('metrics-removal-target');
    $target
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    $direct = node_roles_api_node('direct-removal-consumer');
    $direct->accessibleNodes()->attach($target);
    $requestId = (string) Str::uuid();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $direct->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->deleteJson("/api/v1/nodes/{$target->id}/roles/metrics", [
            'force' => true,
            'purge_data' => false,
            'offline' => true,
        ])
        ->assertForbidden();

    expect(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('input'))
        ->toBe([
            'force' => true,
            'purge_data' => false,
            'offline' => true,
            'role' => 'metrics',
        ])
        ->and($this->reachability->nodeIds)
        ->toBeEmpty()
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty();
});

it('allows Metrics role mutation through directed Gateway access', function (): void {
    $target = node_roles_api_node('gateway-authorized-target');
    $consumer = node_roles_api_node('gateway-authorized-consumer');
    $consumer->accessibleNodes()->attach($this->caller);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
        ->postJson("/api/v1/nodes/{$target->id}/roles", ['role' => 'metrics'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.kind_unavailable');

    $target->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $this
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
        ->deleteJson("/api/v1/nodes/{$target->id}/roles/metrics", ['force' => true])
        ->assertOk();
});

it('keeps ordinary role mutation on target access', function (): void {
    $this->caller->accessibleNodes()->attach($this->node);

    $this->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'app-dev'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'operation.kind_unavailable');
});

it('refuses a recorded Linux ARM role before admission or any Node command', function (): void {
    $this->node->update(['architecture' => 'aarch64']);
    $this->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'app-dev'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'node.architecture_unsupported');
    expect($this->node->roles()->exists())->toBeFalse()->and($this->roleLifecycle->converged)->toBe([]);
});

it('exposes only the exact numeric node role routes and methods', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => str_starts_with(
            (string) $route->getName(),
            'node:role:',
        ))
        ->mapWithKeys(static fn (Route $route): array => [
            $route->getName() => [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'node_where' => $route->wheres['node'] ?? null,
            ],
        ])
        ->all();

    expect($routes)->toBe([
        'node:role:list' => [
            'uri' => 'api/v1/nodes/{node}/roles',
            'methods' => ['GET', 'HEAD'],
            'node_where' => '[0-9]+',
        ],
        'node:role:add' => [
            'uri' => 'api/v1/nodes/{node}/roles',
            'methods' => ['POST'],
            'node_where' => '[0-9]+',
        ],
        'node:role:relocate' => [
            'uri' => 'api/v1/nodes/{node}/roles/{role}/relocate',
            'methods' => ['POST'],
            'node_where' => '[0-9]+',
        ],
        'node:role:remove' => [
            'uri' => 'api/v1/nodes/{node}/roles/{role}',
            'methods' => ['DELETE'],
            'node_where' => '[0-9]+',
        ],
    ]);
});

it('lists assignments in role catalog order with the stable projection', function (): void {
    $appProd = $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'converge:packages',
            'error_code' => 'packages.failed',
        ]);
    $vpn = $this->node
        ->roles()
        ->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);
    $appDev = $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $requestId = (string) Str::uuid();

    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->getJson("/api/v1/nodes/{$this->node->id}/roles")
        ->assertOk()
        ->assertHeader('X-Orbit-Request-Id', $requestId)
        ->assertExactJson([
            'data' => [
                [
                    'id' => $vpn->id,
                    'role' => 'vpn',
                    'status' => 'active',
                    'failed_step' => null,
                    'error_code' => null,
                ],
                [
                    'id' => $appDev->id,
                    'role' => 'app-dev',
                    'status' => 'active',
                    'failed_step' => null,
                    'error_code' => null,
                ],
                [
                    'id' => $appProd->id,
                    'role' => 'app-prod',
                    'status' => 'failed',
                    'failed_step' => 'converge:packages',
                    'error_code' => 'packages.failed',
                ],
            ],
            'meta' => ['request_id' => $requestId],
        ]);
});

it('refuses database settings and documented role conflicts', function (): void {
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", [
            'role' => 'database',
            'settings' => ['engine' => 'mysql'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.body.0', 'The request body contains unsupported top-level keys.');

    expect($this->node->roles()->exists())->toBeFalse();

    $this->node->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'database'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', 'Role [database] conflicts with assigned role [app-prod].');

    expect($this->roleLifecycle->converged)
        ->toBeEmpty()
        ->and($this->node->roles()->where('role', RoleName::Database)->exists())
        ->toBeFalse();
});

describe('analytics role assignment', function (): void {
    beforeEach(function (): void {
        $this->caller->accessibleNodes()->attach($this->node);
    });

    it('requires both storage Process IDs', function (array $body, string $field): void {
        $this
            ->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'analytics', ...$body])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => [$field]]]);

        expect($this->node->roles()->exists())
            ->toBeFalse()
            ->and($this->roleLifecycle->converged)
            ->toBeEmpty();
    })->with([
        'no IDs' => [[], 'postgres_process_id'],
        'no ClickHouse ID' => [['postgres_process_id' => 1], 'clickhouse_process_id'],
        'no PostgreSQL ID' => [['clickhouse_process_id' => 1], 'postgres_process_id'],
        'a string ID' => [['postgres_process_id' => '1', 'clickhouse_process_id' => 2], 'postgres_process_id'],
        'a zero ID' => [['postgres_process_id' => 1, 'clickhouse_process_id' => 0], 'clickhouse_process_id'],
    ]);

    it('prohibits storage Process IDs for every other role', function (): void {
        $storage = analytics_storage_processes();

        $this
            ->postJson("/api/v1/nodes/{$this->node->id}/roles", [
                'role' => 'database',
                'postgres_process_id' => $storage['postgres']->id,
                'clickhouse_process_id' => $storage['clickhouse']->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['postgres_process_id', 'clickhouse_process_id']]]);

        expect($this->node->roles()->exists())->toBeFalse();
    });

    it('refuses an unsupported storage Process before the assignment exists', function (): void {
        $storage = analytics_storage_processes();
        $mysql = analytics_storage_process($storage['node'], 'mysql', 'mysql:8.4');

        $this
            ->postJson("/api/v1/nodes/{$this->node->id}/roles", [
                'role' => 'analytics',
                'postgres_process_id' => $mysql->id,
                'clickhouse_process_id' => $storage['clickhouse']->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.postgres_unsupported');

        expect(NodeRole::query()->where('role', RoleName::Analytics)->exists())
            ->toBeFalse()
            ->and(app(AnalyticsRoleSettingsRepository::class)->find($this->node))
            ->toBeNull()
            ->and($this->roleLifecycle->converged)
            ->toBeEmpty();
    });

    it('refuses a missing storage Process before the assignment exists', function (): void {
        $storage = analytics_storage_processes();

        $this
            ->postJson("/api/v1/nodes/{$this->node->id}/roles", [
                'role' => 'analytics',
                'postgres_process_id' => $storage['postgres']->id,
                'clickhouse_process_id' => $storage['clickhouse']->id + 100,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.clickhouse_process_missing');

        expect(NodeRole::query()->where('role', RoleName::Analytics)->exists())->toBeFalse();
    });

    it('refuses the analytics role on the Gateway Node', function (): void {
        $storage = analytics_storage_processes();

        $this
            ->postJson("/api/v1/nodes/{$this->caller->id}/roles", [
                'role' => 'analytics',
                'postgres_process_id' => $storage['postgres']->id,
                'clickhouse_process_id' => $storage['clickhouse']->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.message', 'Role [analytics] conflicts with assigned role [gateway].');

        expect(app(AnalyticsRoleSettingsRepository::class)->find($this->caller))->toBeNull();
    });
});

it('rejects an unclustered Ingress assignment without partial state', function (): void {
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'ingress'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', 'Role [ingress] requires Cluster membership.');

    expect($this->node->roles()->exists())
        ->toBeFalse()
        ->and($this->roleLifecycle->converged)
        ->toBeEmpty();
});

it('supports Ingress compatibility and rejects app-dev in both assignment orders', function (
    array $existingRoles,
    string $assignedRole,
    bool $accepted,
): void {
    $cluster = Cluster::query()->create(['name' => 'compatibility-'.implode('-', $existingRoles).$assignedRole]);
    $this->node->update(['cluster_id' => $cluster->id]);

    foreach ($existingRoles as $role) {
        $this->node
            ->roles()
            ->create([
                'role' => $role,
                'status' => LifecycleStatus::Active,
                'cluster_id' => in_array($role, ['router', 'ingress'], strict: true) ? $cluster->id : null,
            ]);
    }

    $response = $this->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => $assignedRole]);

    if ($accepted) {
        $response->assertUnprocessable()->assertJsonPath('error.code', 'operation.kind_unavailable');
    } else {
        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    }

    expect($this->node->roles()->where('role', $assignedRole)->exists())->toBeFalse();
})->with([
    'Ingress with Router' => [['router'], 'ingress', true],
    'Ingress with app-prod' => [['app-prod'], 'ingress', true],
    'Ingress with Router and app-prod' => [['router', 'app-prod'], 'ingress', true],
    'Ingress after app-dev' => [['app-dev'], 'ingress', false],
    'app-dev after Ingress' => [['ingress'], 'app-dev', false],
]);

it('refuses Ingress on a Gateway Node before any convergence', function (): void {
    $cluster = Cluster::query()->create(['name' => 'public-private']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'ingress'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', 'Role [ingress] conflicts with assigned role [gateway].');

    expect($this->node->roles()->pluck('role')->map->value->all())
        ->toBe(['gateway'])
        ->and($this->roleLifecycle->converged)
        ->toBe([]);
});

it('removes Ingress through its baseline while the assignment is removing', function (): void {
    $cluster = Cluster::query()->create(['name' => 'baseline-ingress-api']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    $statuses = [];
    $this->roleLifecycle->onRemove = static function (NodeRole $assignment) use (&$statuses): void {
        $statuses[] = NodeRole::query()->findOrFail($assignment->id)->status;
    };

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/ingress", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.removed', true);

    expect($statuses)
        ->toBe([LifecycleStatus::Removing])
        ->and($this->node->roles()->exists())
        ->toBeFalse();
});

it('refuses Ingress removal while a public Route in its Cluster depends on it', function (): void {
    $cluster = Cluster::query()->create(['name' => 'guarded-ingress-api']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    node_roles_api_public_route($cluster);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/ingress", ['force' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', "Role [ingress] cannot be removed while public Routes depend on node [{$this->node->name}].");

    expect($this->node->roles()->where('role', RoleName::Ingress)->sole()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty();
});

it('records a failed Ingress removal and completes it on retry', function (): void {
    $cluster = Cluster::query()->create(['name' => 'retry-ingress-api']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    $this->roleLifecycle->removalFailure = new NodeRoleOperationException(
        step: 'caddy-config',
        errorCode: 'node_role.remove_failed',
        underlyingErrorCode: 'ingress.caddy_config_failed',
        message: 'The Node Caddy build failed.',
    );

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/ingress", ['force' => true])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'node_role.remove_failed')
        ->assertJsonPath('error.details.step', 'remove:caddy-config')
        ->assertJsonPath('error.details.error_code', 'ingress.caddy_config_failed');

    $failed = $this->node->roles()->where('role', RoleName::Ingress)->sole();
    expect($failed->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($failed->failed_step)
        ->toBe('remove:caddy-config')
        ->and($failed->error_code)
        ->toBe('ingress.caddy_config_failed');

    $this->roleLifecycle->removalFailure = null;

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/ingress", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.removed', true);

    expect($this->node->roles()->exists())
        ->toBeFalse()
        ->and($this->roleLifecycle->removed)
        ->toHaveCount(2);
});

it('removes an Ingress whose convergence failed', function (): void {
    $cluster = Cluster::query()->create(['name' => 'failed-converge-ingress-api']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create([
        'role' => RoleName::Ingress,
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'converge:caddy-config',
        'error_code' => 'ingress.caddy_config_failed',
    ]);
    $statuses = [];
    $this->roleLifecycle->onRemove = static function (NodeRole $assignment) use (&$statuses): void {
        $statuses[] = NodeRole::query()->findOrFail($assignment->id)->status;
    };

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/ingress", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.removed', true);

    expect($statuses)
        ->toBe([LifecycleStatus::Removing])
        ->and($this->node->roles()->exists())
        ->toBeFalse()
        ->and($this->roleLifecycle->removed)
        ->toBe([['role' => 'ingress', 'purge_data' => false]]);
});

it('removes any role whose convergence failed through its baseline', function (string $role): void {
    $cluster = Cluster::query()->create(['name' => "failed-converge-{$role}-api"]);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create([
        'role' => $role,
        'cluster_id' => $role === 'ingress' ? $cluster->id : null,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'converge:role-prerequisites',
        'error_code' => 'packages.failed',
    ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/{$role}", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.removed', true);

    expect($this->node->roles()->exists())
        ->toBeFalse()
        ->and($this->roleLifecycle->removed)
        ->toBe([['role' => $role, 'purge_data' => false]]);
})->with(['gateway', 'ingress', 'app-dev', 'app-prod', 'metrics', 'websocket', 'analytics', 'database']);

it('refuses to remove a role while another operation holds it', function (LifecycleStatus $status, ?string $failedStep): void {
    $this->node->roles()->create([
        'role' => RoleName::AppProd,
        'status' => $status,
        'failed_step' => $failedStep,
    ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/app-prod", ['force' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', "Role [app-prod] cannot be removed from status [{$status->value}].");

    expect($this->node->roles()->sole()->status)
        ->toBe($status)
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty();
})->with([
    'converging' => [LifecycleStatus::Provisioning, null],
    'removing' => [LifecycleStatus::Removing, null],
]);

it('removes an unreachable Ingress on the Gateway side and lists what stays on the Node', function (): void {
    $cluster = Cluster::query()->create(['name' => 'offline-ingress-api']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    $this->reachability->degradation = ExporterDegradationReason::Unreachable;

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/ingress", ['force' => true, 'offline' => true])
        ->assertOk()
        ->assertJsonPath('data.removed', true)
        ->assertJsonPath('data.retained_on_node', [
            'Caddy configuration that serves the ingress role on every address',
            'Orbit firewall rules for public HTTP and HTTPS on the ingress role',
        ]);

    expect($this->node->roles()->exists())
        ->toBeFalse()
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty()
        ->and($this->roleLifecycle->removedUnreachable)
        ->toBe(['ingress']);
});

it('returns standard validation failures for protected unknown and duplicate assignments', function (
    string $role,
    string $fixture,
): void {
    if ($fixture === 'preassigned') {
        $this->node
            ->roles()
            ->create([
                'role' => RoleName::AppDev,
                'status' => LifecycleStatus::Active,
            ]);
    }

    $response = $this->postJson("/api/v1/nodes/{$this->node->id}/roles", [
        'role' => $role,
        'converge_existing' => false,
    ]);

    $response
        ->assertUnprocessable()
        ->assertHeader('X-Orbit-Request-Id')
        ->assertJsonPath('error.code', 'validation.failed');

    expect($response->getContent())
        ->not
        ->toContain('trace', 'stdout', 'stderr')
        ->and($this->roleLifecycle->converged)
        ->toBeEmpty();
})->with([
    'vpn is protected' => ['vpn', 'unassigned'],
    'unknown role' => ['future-role', 'unassigned'],
    'existing role requires explicit convergence' => ['app-dev', 'preassigned'],
]);

it('refuses a second gateway assignment because the role is a singleton', function (): void {
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'gateway'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', 'Role [gateway] is already assigned to node [gateway-peer].');

    expect($this->node->roles()->where('role', RoleName::Gateway)->exists())
        ->toBeFalse()
        ->and($this->roleLifecycle->converged)
        ->toBeEmpty();
});

it('relocates the singleton gateway assignment onto the target node', function (): void {
    $requestId = (string) Str::uuid();
    $sourceAssignment = $this->caller->roles()->where('role', RoleName::Gateway)->sole();
    $this->caller->roles()->create([
        'role' => RoleName::Vpn,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson("/api/v1/nodes/{$this->node->id}/roles/gateway/relocate", ['force' => true])
        ->assertOk()
        ->assertHeader('X-Orbit-Request-Id', $requestId)
        ->assertExactJson([
            'data' => [
                'node_id' => $this->node->id,
                'node_name' => $this->node->name,
                'role' => 'gateway',
                'degradation' => null,
                'retained_on_node' => [],
                'follow_up' => null,
                'assignment' => [
                    'id' => $sourceAssignment->id,
                    'role' => 'gateway',
                    'status' => 'active',
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'removed' => false,
            ],
            'meta' => ['request_id' => $requestId],
        ]);

    expect($sourceAssignment->refresh()->node_id)
        ->toBe($this->node->id)
        ->and($this->caller->roles()->where('role', RoleName::Gateway)->exists())
        ->toBeFalse()
        ->and($this->caller->roles()->where('role', RoleName::Vpn)->exists())
        ->toBeTrue()
        ->and(NodeRole::query()->where('role', RoleName::Gateway)->count())
        ->toBe(1)
        ->and($this->route->events)
        ->toBe(["converge:{$this->node->name}", "remove:{$this->caller->name}"]);
});

it('returns the follow-up when the relocated gateway target does not get the private DNS route', function (): void {
    $this->caller->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $this->route->followUp = 'The Gateway machine does not route the private domain to Orbit VPN DNS (vpn.dns_resolver_failed).';

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles/gateway/relocate", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.assignment.status', 'active')
        ->assertJsonPath('data.follow_up', 'The Gateway machine does not route the private domain to Orbit VPN DNS (vpn.dns_resolver_failed).');
});

it('refuses to relocate the gateway role onto an Ingress Node', function (): void {
    $assignment = $this->caller->roles()->where('role', RoleName::Gateway)->sole();
    $cluster = Cluster::query()->create(['name' => 'relocate-onto-ingress']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create(['role' => RoleName::Ingress, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles/gateway/relocate", ['force' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', 'Role [gateway] conflicts with assigned role [ingress].');

    expect($assignment->refresh()->node_id)->toBe($this->caller->id)
        ->and($this->roleLifecycle->converged)->toBe([]);
});

it('requires force before relocating the gateway role', function (): void {
    $assignment = $this->caller->roles()->where('role', RoleName::Gateway)->sole();

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles/gateway/relocate", ['force' => false])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', 'Use --force to relocate this node role.')
        ->assertJsonPath('error.details.reason', 'destructive_consent_required');

    expect($assignment->refresh()->node_id)->toBe($this->caller->id);
});

it('refuses to relocate a role that is not relocatable', function (): void {
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles/vpn/relocate", ['force' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.message', 'Role [vpn] cannot be relocated.');
});

it('relocates the singleton websocket assignment onto the target node', function (): void {
    $source = node_roles_api_node('websocket-source');
    $source->roles()->create([
        'role' => RoleName::WebSocket,
        'status' => LifecycleStatus::Active,
    ]);
    $requestId = (string) Str::uuid();

    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson("/api/v1/nodes/{$this->node->id}/roles/websocket/relocate", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.role', 'websocket')
        ->assertJsonPath('data.node_id', $this->node->id)
        ->assertJsonPath('data.assignment.role', 'websocket')
        ->assertJsonPath('data.assignment.status', 'active');

    expect($source->roles()->where('role', RoleName::WebSocket)->exists())
        ->toBeFalse()
        ->and($this->node->roles()->where('role', RoleName::WebSocket)->exists())
        ->toBeTrue()
        ->and($this->roleLifecycle->converged)
        ->toBe(['websocket'])
        ->and($this->roleLifecycle->removed)
        ->toBe([['role' => 'websocket', 'purge_data' => false]]);
});

it('relocates leftover websocket resources when the target already holds the role', function (): void {
    $leftover = node_roles_api_node('websocket-leftover');
    $this->node->roles()->create([
        'role' => RoleName::WebSocket,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles/websocket/relocate", [
            'force' => true,
            'from' => $leftover->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.role', 'websocket')
        ->assertJsonPath('data.node_id', $this->node->id);

    expect($this->node->roles()->where('role', RoleName::WebSocket)->count())
        ->toBe(1)
        ->and($this->roleLifecycle->converged)
        ->toBe(['websocket'])
        ->and($this->roleLifecycle->removed)
        ->toBe([['role' => 'websocket', 'purge_data' => false]]);
});

it('relocates the singleton metrics assignment onto the target node', function (): void {
    $source = node_roles_api_node('metrics-source');
    $source->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles/metrics/relocate", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.role', 'metrics')
        ->assertJsonPath('data.node_id', $this->node->id);

    expect($source->roles()->where('role', RoleName::Metrics)->exists())
        ->toBeFalse()
        ->and($this->roleLifecycle->converged)
        ->toBe(['metrics']);
});

it('includes the same role enum validation details for add and remove', function (): void {
    $add = $this
        ->postJson("/api/v1/nodes/{$this->node->id}/roles", ['role' => 'nosuch'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
    $remove = $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/nosuch", ['force' => true])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($add->json('error.details.role'))
        ->not->toBeEmpty()
        ->and($remove->json('error.details.role'))
        ->toBe($add->json('error.details.role'))
        ->and($this->roleLifecycle->converged)
        ->toBeEmpty()
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty();
});

it('always returns the exact preview without mutating when force is absent or false', function (
    array $body,
    array $expectedInput,
): void {
    $assignment = $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $requestId = (string) Str::uuid();

    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/app-dev", $body)
        ->assertUnprocessable()
        ->assertHeader('X-Orbit-Request-Id', $requestId)
        ->assertExactJson([
            'error' => [
                'code' => 'validation.failed',
                'message' => 'Use --force to remove this node role.',
                'details' => [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'role' => 'app-dev',
                    'dependents' => [],
                ],
            ],
        ]);

    expect($assignment->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('input'))
        ->toBe($expectedInput)
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty();
})->with([
    'absent force' => [
        ['purge_data' => false],
        ['purge_data' => false, 'role' => 'app-dev'],
    ],
    'explicit false' => [
        ['force' => false],
        ['force' => false, 'role' => 'app-dev'],
    ],
]);

it('returns the exact mutation snapshot and records the complete SDK input on confirmed removal', function (
    bool $offline,
): void {
    $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $requestId = (string) Str::uuid();

    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/app-dev", [
            'force' => true,
            'purge_data' => true,
            'offline' => $offline,
        ])
        ->assertOk()
        ->assertHeader('X-Orbit-Request-Id', $requestId)
        ->assertExactJson([
            'data' => [
                'node_id' => $this->node->id,
                'node_name' => $this->node->name,
                'role' => 'app-dev',
                'degradation' => null,
                'retained_on_node' => [],
                'follow_up' => null,
                'assignment' => null,
                'removed' => true,
            ],
            'meta' => ['request_id' => $requestId],
        ]);

    expect(NodeRole::query()->where('node_id', $this->node->id)->count())
        ->toBe(0)
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('input'))
        ->toBe([
            'force' => true,
            'purge_data' => true,
            'offline' => $offline,
            'role' => 'app-dev',
        ])
        ->and($this->reachability->nodeIds)
        ->toBe($offline ? [$this->node->id] : [])
        ->and($this->roleLifecycle->removed)
        ->toBe([['role' => 'app-dev', 'purge_data' => true]]);
})->with([
    'SDK default offline false' => [false],
    'explicit offline true' => [true],
]);

it('does not let force offline or purge remove an app-dev role beneath an Instance', function (array $body): void {
    $cluster = Cluster::query()->create(['name' => 'development', 'state' => ClusterState::Active]);
    $this->node->update(['cluster_id' => $cluster->id]);
    $assignment = $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
        'checkout_path' => '/srv/orbit/apps/acme/dev',
        'branch' => 'dev',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/app-dev", $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.reason', 'instances_attached');

    expect($assignment->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and(Instance::query()->count())
        ->toBe(1)
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty();
})->with([
    'force' => [['force' => true]],
    'force and purge' => [['force' => true, 'purge_data' => true]],
    'force and offline' => [['force' => true, 'offline' => true]],
]);

it('requires force when purge data is true before the removal action runs', function (): void {
    $assignment = $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/app-dev", [
            'force' => false,
            'purge_data' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.force.0', 'The force field must be true when purge data is requested.');

    expect($assignment->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty();
});

it('requires force when the offline claim is made before the removal action runs', function (): void {
    $assignment = $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/app-dev", [
            'force' => false,
            'offline' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.force.0', 'The force field must be true when offline removal is requested.');

    expect($assignment->refresh()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->roleLifecycle->removed)
        ->toBeEmpty();
});

it('requires active peer identity and direct target access for all role routes', function (): void {
    $direct = node_roles_api_node('direct-peer');
    $direct->accessibleNodes()->attach($this->node);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $direct->wireguard_ip])
        ->getJson("/api/v1/nodes/{$this->node->id}/roles")
        ->assertOk();

    $denied = node_roles_api_node('denied-peer');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->getJson("/api/v1/nodes/{$this->node->id}/roles")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.44.99.99'])
        ->getJson("/api/v1/nodes/{$this->node->id}/roles")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'peer.identity_unknown');
});

it('returns safe binding and inactive-node failures', function (): void {
    $inactive = node_roles_api_node('inactive-target', LifecycleStatus::Failed);

    $this
        ->getJson('/api/v1/nodes/999999/roles')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');
    $this
        ->getJson('/api/v1/nodes/not-numeric/roles')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');
    $this
        ->postJson("/api/v1/nodes/{$inactive->id}/roles", ['role' => 'app-dev'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
});

it('returns a safe correlated 502 for removal failure', function (): void {
    $sentinel = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $this->roleLifecycle->removalFailure = new NodeRoleOperationException(
        step: 'firewall',
        errorCode: 'node_role.remove_failed',
        underlyingErrorCode: 'firewall.failed',
        message: 'Role removal failed.',
        result: new CommandResult(24, $sentinel, $sentinel, 11, false),
    );

    $response = $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->deleteJson("/api/v1/nodes/{$this->node->id}/roles/app-dev", [
            'force' => true,
            'purge_data' => false,
            'offline' => false,
        ]);

    $response
        ->assertStatus(502)
        ->assertHeader('X-Orbit-Request-Id', $requestId)
        ->assertExactJson([
            'error' => [
                'code' => 'node_role.remove_failed',
                'message' => 'Role removal failed. Retry with --offline if node [role-target] is unreachable.',
                'details' => ['step' => 'remove:firewall', 'error_code' => 'firewall.failed'],
            ],
        ]);

    expect($response->getContent())
        ->not
        ->toContain($sentinel)
        ->and(Activity::query()->where('request_id', $requestId)->sole()->error_code)
        ->toBe('node_role.remove_failed')
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('input'))
        ->toBe([
            'force' => true,
            'purge_data' => false,
            'offline' => false,
            'role' => 'app-dev',
        ]);
});

it('rejects unsafe raw JSON without mutation or rejected activity input', function (
    string $method,
    string $path,
    string $json,
): void {
    $requestId = (string) Str::uuid();
    $sentinel = 'rejected-raw-sentinel';

    if ($method === 'DELETE') {
        $this->node
            ->roles()
            ->create([
                'role' => RoleName::AppDev,
                'status' => LifecycleStatus::Active,
            ]);
    }

    $response = node_roles_raw_json(
        test: $this,
        method: $method,
        uri: str_replace('{node}', (string) $this->node->id, $path),
        json: str_replace('{sentinel}', $sentinel, $json),
        remoteAddress: (string) $this->caller->wireguard_ip,
        requestId: $requestId,
    );

    $response
        ->assertUnprocessable()
        ->assertHeader('X-Orbit-Request-Id', $requestId)
        ->assertJsonPath('error.code', 'validation.failed');

    $activity = Activity::query()->where('request_id', $requestId)->sole();

    expect($response->getContent())
        ->not->toContain($sentinel)->and($activity->properties?->get('input'))->toBe([])->and(
            json_encode($activity->properties?->toArray()),
        )
        ->not->toContain(
            $sentinel,
        )->and($this->roleLifecycle->converged)->toBeEmpty()->and($this->roleLifecycle->removed)->toBeEmpty();
})->with([
    'duplicate key' => [
        'POST',
        '/api/v1/nodes/{node}/roles',
        '{"role":"app-dev","role":"{sentinel}"}',
    ],
    'escaped duplicate key' => [
        'POST',
        '/api/v1/nodes/{node}/roles',
        '{"role":"app-dev","r\\u006fle":"{sentinel}"}',
    ],
    'unknown key' => [
        'POST',
        '/api/v1/nodes/{node}/roles',
        '{"role":"app-dev","unknown":"{sentinel}"}',
    ],
    'unknown role' => [
        'POST',
        '/api/v1/nodes/{node}/roles',
        '{"role":"{sentinel}","converge_existing":false}',
    ],
    'malformed JSON' => [
        'POST',
        '/api/v1/nodes/{node}/roles',
        '{"role":"app-dev","unknown":"{sentinel}"',
    ],
    'non-boolean add flag' => [
        'POST',
        '/api/v1/nodes/{node}/roles',
        '{"role":"app-dev","converge_existing":"{sentinel}"}',
    ],
    'non-boolean remove flag' => [
        'DELETE',
        '/api/v1/nodes/{node}/roles/app-dev',
        '{"force":"{sentinel}","purge_data":false}',
    ],
    'non-boolean offline flag' => [
        'DELETE',
        '/api/v1/nodes/{node}/roles/app-dev',
        '{"force":true,"offline":"{sentinel}"}',
    ],
    'malformed removal JSON' => [
        'DELETE',
        '/api/v1/nodes/{node}/roles/app-dev',
        '{"force":true,"offline":"{sentinel}"',
    ],
    'duplicate removal key' => [
        'DELETE',
        '/api/v1/nodes/{node}/roles/app-dev',
        '{"force":true,"offline":false,"offline":"{sentinel}"}',
    ],
    'escaped duplicate removal key' => [
        'DELETE',
        '/api/v1/nodes/{node}/roles/app-dev',
        '{"force":true,"offline":false,"offl\\u0069ne":"{sentinel}"}',
    ],
]);

function node_roles_raw_json(
    TestCase $test,
    string $method,
    string $uri,
    string $json,
    string $remoteAddress,
    string $requestId,
): TestResponse {
    return $test->call(
        $method,
        $uri,
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORBIT_REQUEST_ID' => $requestId,
            'REMOTE_ADDR' => $remoteAddress,
        ],
        content: $json,
    );
}

function node_roles_api_node(
    string $name,
    LifecycleStatus $status = LifecycleStatus::Active,
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $name.'.example.test',
        'wireguard_ip' => '10.44.10.'.(Node::query()->count() + 2),
    ]);
}

function node_roles_api_public_route(Cluster $cluster): OrbitRoute
{
    $project = Project::query()->create([
        'name' => 'Public',
        'slug' => 'public-'.$cluster->id,
        'repository_url' => 'https://github.com/acme/public.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return OrbitRoute::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => "public-{$cluster->id}.example.com",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
    ]);
}

final class NodeRoleApiLifecycleFake implements NodeRoleDependentCleaner, RoleBaselineConverger
{
    /** @var list<string> */
    public array $converged = [];

    /** @var list<array{role: string, purge_data: bool}> */
    public array $removed = [];

    public ?NodeRoleOperationException $convergenceFailure = null;

    /** A follow-up the next convergence records, as a step that fails without failing the role does. */
    public ?string $followUp = null;

    public ?NodeRoleOperationException $removalFailure = null;

    /** @var list<string> */
    public array $removedUnreachable = [];

    /** @var (Closure(NodeRole): void)|null */
    public ?Closure $onRemove = null;

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->converged[] = $assignment->role->value;

        if ($this->followUp !== null) {
            app(NodeRoleFollowUpReport::class)->record($this->followUp);
            $this->followUp = null;
        }

        if ($this->convergenceFailure instanceof NodeRoleOperationException) {
            throw $this->convergenceFailure;
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->removed[] = [
            'role' => $assignment->role->value,
            'purge_data' => $purgeData,
        ];

        if ($this->onRemove instanceof Closure) {
            ($this->onRemove)($assignment);
        }

        if ($this->removalFailure instanceof NodeRoleOperationException) {
            throw $this->removalFailure;
        }
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->removedUnreachable[] = $assignment->role->value;
    }

    public function clean(NodeRoleDependencySet $dependencies): void {}
}

final class NodeRoleApiReachabilityFake implements NodeReachabilityProbe
{
    /** @var list<int> */
    public array $nodeIds = [];

    public ?ExporterDegradationReason $degradation = null;

    public function degradation(Node $node): ?ExporterDegradationReason
    {
        $this->nodeIds[] = $node->id;

        return $this->degradation;
    }
}

it('refuses ci-runner on trust-anchor Nodes in either assignment order', function (string $first, string $second): void {
    $target = node_roles_api_node('ci-conflict-target');
    $target->roles()->create(['role' => $first, 'status' => LifecycleStatus::Active]);

    $this->postJson("/api/v1/nodes/{$target->id}/roles", ['role' => $second])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
    expect($this->roleLifecycle->converged)->toBe([]);
})->with([['gateway', 'ci-runner'], ['vpn', 'ci-runner'], ['ci-runner', 'gateway']]);
