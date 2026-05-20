<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\NodeRoleDependencyInspector;
use App\Services\Nodes\Roles\RoleBaselines\AgentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $developmentDnsConfigDir = storage_path('framework/testing/node-role-assignment-dns/'.bin2hex(random_bytes(6)));

    app()->instance(DevelopmentDnsMappingEnactor::class, new DevelopmentDnsMappingEnactor($developmentDnsConfigDir));
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

describe('node role assignment service', function (): void {
    it('activates a compatible role after convergence succeeds', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->role)
            ->toBe('database')
            ->and($assignment->converged_at)
            ->not->toBeNull()
            ->and($assignment->last_error)
            ->toBeNull()
            ->and($assignment->settings)
            ->toBe([]);
    });

    it('rejects duplicate role assignment before hitting the unique index', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'database', []))
            ->toThrow(InvalidArgumentException::class, "Role 'database' is already assigned to node '{$node->name}'.");
    });

    it('rejects app-development assignment when another active node owns the tld', function (): void {
        $existingNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'app',
            'environment' => 'development',
            'tld' => null,
            'wireguard_address' => '10.0.0.11',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $existingNode->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'wireguard_address' => '10.0.0.12',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-development', ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Development TLD 'test' is already assigned to another node.");

        expect($node->roleAssignments()->where('role', 'app-development')->exists())->toBeFalse();
    });

    it('rejects app-development updates when another active node owns the tld', function (): void {
        $existingNode = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'app',
            'environment' => 'development',
            'tld' => null,
            'wireguard_address' => '10.0.0.11',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $existingNode->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'app',
            'environment' => 'development',
            'wireguard_address' => '10.0.0.12',
        ]);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'app-development', ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Development TLD 'test' is already assigned to another node.");

        expect($assignment->fresh()->settings)->toBe(['tld' => 'old'])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->fresh()->last_error)->toBeNull();
    });

    it('updates legacy node shadows when roles are added and removed', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'environment' => null,
            'tld' => null,
            'wireguard_address' => '10.0.0.10',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-development', ['tld' => 'test']);

        $node->refresh();

        expect($node->role)->toBe('app')
            ->and($node->environment)->toBe('development')
            ->and($node->tld)->toBe('test');

        app(NodeRoleAssignmentService::class)->add($node, 'database', []);
        app(NodeRoleAssignmentService::class)->remove($node->refresh(), 'app-development', force: true);

        $node->refresh();

        expect($node->role)->toBe('database')
            ->and($node->environment)->toBeNull()
            ->and($node->tld)->toBeNull();

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true);

        $node->refresh();

        expect($node->role)->toBe('control')
            ->and($node->environment)->toBeNull()
            ->and($node->tld)->toBeNull();
    });

    it('materializes docker as a desired tool for database roles', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'docker')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('running')
            ->and(NodeTool::query()
                ->where('node_id', $node->id)
                ->whereIn('name', ['mysql', 'postgres', 'sqlite3'])
                ->exists())->toBeFalse();
    });

    it('materializes sqlite3 as a desired tool for development app roles', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'wireguard_address' => '10.6.0.20',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-development', ['tld' => 'test']);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'sqlite3')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('materializes the production app runtime baseline as desired tools', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'host' => 'app-prod-1.example.com',
        ]);

        app(NodeRoleAssignmentService::class)->add($node, 'app-production', []);

        $tools = NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['caddy', 'php', 'sqlite3', 'supervisor'])
            ->orderBy('name')
            ->get();

        expect($tools->pluck('name')->all())
            ->toBe(['caddy', 'php', 'sqlite3', 'supervisor'])
            ->and($tools->mapWithKeys(fn (NodeTool $tool): array => [$tool->name => $tool->expected_state])->all())
            ->toBe([
                'caddy' => 'running',
                'php' => 'running',
                'sqlite3' => 'installed',
                'supervisor' => 'running',
            ]);
    });

    it('rejects conflicting roles', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-production', []))
            ->toThrow(InvalidArgumentException::class, "Role 'app-production' conflicts with active role 'app-development'.");
    });

    it('rejects pending and error role conflicts', function (string $status): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => $status,
            'settings' => ['tld' => 'test'],
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-production', []))
            ->toThrow(InvalidArgumentException::class, "Role 'app-production' conflicts with {$status} role 'app-development'.");
    })->with([
        NodeRoleStatus::Pending->value,
        NodeRoleStatus::Error->value,
    ]);

    it('rejects updates when pending and error role conflicts exist', function (string $status): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',
            'role' => 'control',
            'wireguard_address' => '10.0.0.10',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-production',
            'status' => $status,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'app-development', ['tld' => 'new']))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' conflicts with {$status} role 'app-production'.");

        expect($assignment->fresh()->settings)->toBe(['tld' => 'old'])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->fresh()->last_error)->toBeNull();
    })->with([
        NodeRoleStatus::Pending->value,
        NodeRoleStatus::Error->value,
    ]);

    it('rejects roles that conflict with an active gateway assignment', function (string $role, array $settings): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'gateway.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, $role, $settings))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' conflicts with active role 'gateway'.");
    })->with([
        'app-development' => ['app-development', ['tld' => 'test']],
        'app-production' => ['app-production', []],
        'database' => ['database', []],
    ]);

    it('ignores removing assignments during conflict checks', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'host' => 'app-prod-1.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Removing->value,
            'settings' => ['tld' => 'test'],
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'app-production', []);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->role)
            ->toBe('app-production');
    });

    it('marks role as error when convergence fails', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct()
            {
                parent::__construct(
                    app(GatewayRoleBaseline::class),
                    app(AppDevelopmentRoleBaseline::class),
                    app(AppProductionRoleBaseline::class),
                    app(DatabaseRoleBaseline::class),
                    app(AgentRoleBaseline::class),
                );
            }

            public function converge(Node $node, NodeRoleAssignment $assignment): void
            {
                throw new RuntimeException('Docker is missing.');
            }
        });

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'database', []);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Error->value)
            ->and($assignment->last_error)
            ->toBe('Docker is missing.')
            ->and($assignment->converged_at)
            ->toBeNull();
    });

    it('marks app-development as error when the development dns mapping cannot be materialized', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'wireguard_address' => null,
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->add($node, 'app-development', ['tld' => 'test']);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Error->value)
            ->and($assignment->last_error)
            ->toBe('The app-development role requires a WireGuard address so the development DNS mapping can be materialized.')
            ->and($assignment->converged_at)
            ->toBeNull();
    });

    it('rejects production and database baselines for nodes with an assigned gateway role', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'host' => 'gateway.example.com',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $productionAssignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => 'app-production',
            'status' => NodeRoleStatus::Pending->value,
        ]);
        $databaseAssignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Pending->value,
        ]);

        expect(fn () => app(AppProductionRoleBaseline::class)->converge($node, $productionAssignment))
            ->toThrow(RuntimeException::class, 'The app-production role cannot be assigned to a gateway node.');

        expect(fn () => app(DatabaseRoleBaseline::class)->converge($node, $databaseAssignment))
            ->toThrow(RuntimeException::class, 'The database role cannot be assigned to a gateway node.');
    });

    it('updates an existing role and re-activates it after convergence succeeds', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',
            'role' => 'control',
            'wireguard_address' => '10.0.0.10',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->update($node, 'app-development', ['tld' => 'new']);

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->settings)
            ->toBe(['tld' => 'new'])
            ->and($assignment->last_error)
            ->toBeNull()
            ->and($assignment->converged_at)
            ->not->toBeNull();
    });

    it('removes the previous development dns mapping after an app-development tld update', function (): void {
        $configDir = app(DevelopmentDnsMappingEnactor::class)->configDir();

        File::deleteDirectory($configDir);
        File::ensureDirectoryExists($configDir);
        File::put("{$configDir}/old.conf", 'stale mapping');

        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',
            'role' => 'app',
            'environment' => 'development',
            'tld' => 'old',
            'wireguard_address' => '10.0.0.10',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);

        $assignment = app(NodeRoleAssignmentService::class)->update($node, 'app-development', ['tld' => 'new']);

        expect($assignment->status)->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->settings)->toBe(['tld' => 'new'])
            ->and("{$configDir}/old.conf")->not->toBeFile()
            ->and("{$configDir}/new.conf")->toBeFile();
    });

    it('rejects updates when a conflicting role is active', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',
            'role' => 'control',
            'wireguard_address' => '10.0.0.10',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'old'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-production',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, 'app-development', ['tld' => 'new']))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' conflicts with active role 'app-production'.");

        expect($assignment->fresh()->settings)->toBe(['tld' => 'old'])
            ->and($assignment->fresh()->status)->toBe(NodeRoleStatus::Active->value)
            ->and($assignment->fresh()->last_error)->toBeNull();
    });

    it('rejects unsupported platforms', function (): void {
        $node = Node::factory()->create([
            'platform' => 'macos_15',
            'role' => 'control',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'app-development', ['tld' => 'test']))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' does not support platform 'macos_15'.");
    });

    it('rejects gateway-coupled role assignment through the normal service', function (string $role): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, $role, []))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' is gateway-coupled and cannot be assigned independently.");

        expect(fn () => app(NodeRoleAssignmentService::class)->addDuringCreation($node, $role, []))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' is gateway-coupled and cannot be assigned independently.");
    })->with([
        'gateway' => 'gateway',
        'vpn' => 'vpn',
    ]);

    it('rejects gateway-coupled role updates through the normal service', function (string $role): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->update($node, $role, []))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' is gateway-coupled and cannot be assigned independently.");
    })->with([
        'gateway' => 'gateway',
        'vpn' => 'vpn',
    ]);

    it('rejects unknown roles during removal through the registry', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'queue'))
            ->toThrow(InvalidArgumentException::class, 'Unknown node role [queue].');
    });

    it('rejects gateway-coupled role removal through the normal service', function (string $role): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, $role))
            ->toThrow(InvalidArgumentException::class, "Role '{$role}' is gateway-coupled and cannot be assigned independently.");
    })->with([
        'gateway' => 'gateway',
        'vpn' => 'vpn',
    ]);

    it('rejects agent assignment through the normal service', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        expect(fn () => app(NodeRoleAssignmentService::class)->add($node, 'agent', []))
            ->toThrow(InvalidArgumentException::class, "Role 'agent' cannot be assigned through this service.");
    });

    it('allows agent assignment during node creation', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'role' => 'control',
            'wireguard_address' => '10.6.0.50',
        ]);

        app()->instance(RemoteShell::class, new class implements RemoteShell
        {
            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: '',
                    stderr: '',
                    durationMs: 0,
                );
            }
        });

        $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation($node, 'agent', ['tld' => 'agent']);

        expect($assignment->role)->toBe('agent')
            ->and($assignment->status)->toBe(NodeRoleStatus::Active->value);
    });

    it('blocks removal when dependents exist and force is false', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
        ]);

        App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-development'))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' cannot be removed while dependents exist.");
    });

    it('rechecks removal dependents inside the transaction before destructive cleanup', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
        ]);
        $inspector = new class extends NodeRoleDependencyInspector
        {
            public int $calls = 0;

            public bool $removed = false;

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                $this->calls++;

                return $this->calls === 1 ? [] : ['1 development app record'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->removed = true;
            }
        };
        app()->instance(NodeRoleDependencyInspector::class, $inspector);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-development'))
            ->toThrow(InvalidArgumentException::class, "Role 'app-development' cannot be removed while dependents exist.");

        expect($assignment->fresh()->status)->toBe(NodeRoleStatus::Active->value)
            ->and($inspector->calls)->toBe(2)
            ->and($inspector->removed)->toBeFalse();
    });

    it('requires force when purge data is requested', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'database', purgeData: true))
            ->toThrow(InvalidArgumentException::class, 'The purgeData option requires force.');
    });

    it('forces removal by deleting Orbit-owned dependents and deleting the assignment', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);
        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'app-development', force: true);

        expect(App::query()->whereKey($app->id)->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('removes Orbit-owned dependents before removing role baselines', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
        ]);

        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject;

        app()->instance(NodeRoleDependencyInspector::class, new class($events) extends NodeRoleDependencyInspector
        {
            /**
             * @param  ArrayObject<int, string>  $events
             */
            public function __construct(private readonly ArrayObject $events) {}

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                return ['1 development app record'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->events->append('dependents');
            }
        });

        app()->instance(NodeRoleBaselineConverger::class, new class($events) extends NodeRoleBaselineConverger
        {
            /**
             * @param  ArrayObject<int, string>  $events
             */
            public function __construct(private readonly ArrayObject $events) {}

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                $this->events->append('baseline');
            }
        });

        app(NodeRoleAssignmentService::class)->remove($node, 'app-development', force: true);

        expect($events->getArrayCopy())->toBe(['dependents', 'baseline']);
    });

    it('removes app dependents and passes purge intent when purge data is requested', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
        ]);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);
        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'app-development', force: true, purgeData: true);

        expect(App::query()->whereKey($app->id)->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('forces database role removal by deleting database dependents and clearing docker baseline intent', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'docker',
            'expected_state' => 'running',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true);

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeFalse()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'docker')->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('removes database dependents and passes purge intent when purge data is requested', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'database',
            'status' => NodeRoleStatus::Active->value,
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'docker',
            'expected_state' => 'running',
        ]);

        app(NodeRoleAssignmentService::class)->remove($node, 'database', force: true, purgeData: true);

        expect(NodeTool::query()->where('node_id', $node->id)->whereIn('name', ['postgres', 'docker'])->exists())->toBeFalse()
            ->and($node->fresh()->roleAssignments)->toHaveCount(0);
    });

    it('leaves the assignment in error and keeps dependents intact when baseline removal fails', function (): void {
        $node = Node::factory()->create(['platform' => 'ubuntu']);
        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => NodeRoleStatus::Active->value,
            'settings' => ['tld' => 'test'],
        ]);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);
        ProxyRoute::factory()->forApp($app)->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
        ]);
        $inspector = new class extends NodeRoleDependencyInspector
        {
            public bool $removed = false;

            public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
            {
                return ['1 development app record'];
            }

            public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
            {
                $this->removed = true;
            }
        };
        app()->instance(NodeRoleDependencyInspector::class, $inspector);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger
        {
            public function __construct()
            {
                parent::__construct(
                    app(GatewayRoleBaseline::class),
                    app(AppDevelopmentRoleBaseline::class),
                    app(AppProductionRoleBaseline::class),
                    app(DatabaseRoleBaseline::class),
                    app(AgentRoleBaseline::class),
                );
            }

            public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
            {
                throw new RuntimeException('Cleanup failed.');
            }
        });

        expect(fn () => app(NodeRoleAssignmentService::class)->remove($node, 'app-development', force: true))
            ->toThrow(RuntimeException::class, 'Cleanup failed.');

        expect($assignment->fresh()->status)
            ->toBe(NodeRoleStatus::Error->value)
            ->and($assignment->fresh()->last_error)
            ->toBe('Cleanup failed.')
            ->and($inspector->removed)
            ->toBeTrue()
            ->and(App::query()->whereKey($app->id)->exists())
            ->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'docs.test')->exists())
            ->toBeTrue();
    });
});
