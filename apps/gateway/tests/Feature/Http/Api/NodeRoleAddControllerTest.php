<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\RoleBaselines\AgentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleApiTestHelpers.php';

describe('NodeRoleAddController', function (): void {
    it('adds a role for an authorized caller and returns the assignment payload', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'database',
            'settings' => [],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', 'database')
            ->assertJsonPath('success.data.assignment.status', 'active')
            ->assertJsonPath('success.data.assignment.settings', [])
            ->assertJsonPath('success.data.assignment.last_error', null);

        expect(
            $target
                ->roleAssignments()
                ->where('role', 'database')
                ->where('status', NodeRoleStatus::Active->value)
                ->exists(),
        )->toBeTrue();
    });

    it('reconverges an existing metrics role when requested', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);
        createNodeRoleApiContractAssignment($target, 'metrics');
        app()->instance(RemoteShell::class, new NodeRoleAddMetricsRecordingShell);

        Process::factory()
            ->forOwner($target)
            ->create([
                'name' => 'prometheus',
                'command' => 'prometheus --config.file=/etc/prometheus/prometheus.yml',
                'runtime' => ProcessRuntime::DockerSwarm,
                'runtime_config' => [
                    'service' => 'prometheus',
                    'endpoint' => ['port' => 9090],
                ],
            ]);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'metrics',
            'settings' => [],
            'reconverge_existing' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', 'metrics')
            ->assertJsonPath('success.data.assignment.status', 'active');

        $prometheus = Process::query()
            ->where('node_id', $target->id)
            ->where('name', 'prometheus')
            ->sole();

        expect($prometheus->runtime_config['managed_files'][0]['content'])
            ->toContain("'10.6.0.20:9100'")
            ->and($prometheus->runtime_config['bind_mounts'][0])
            ->toMatchArray([
                'source' => '/var/lib/orbit/processes/prometheus/prometheus.yml',
                'target' => '/etc/prometheus/prometheus.yml',
                'read_only' => true,
            ]);
    });

    it('returns an error envelope when role convergence fails', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        app()->instance(NodeRoleBaselineConverger::class, new class extends NodeRoleBaselineConverger {
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

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'database',
            'settings' => [],
        ]);

        $response
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'node_role.convergence_failed')
            ->assertJsonPath('error.message', "Role 'database' convergence failed.")
            ->assertJsonPath('error.meta.role', 'database')
            ->assertJsonPath('error.meta.status', 'error')
            ->assertJsonPath('error.meta.last_error', 'Docker is missing.')
            ->assertJsonMissingPath('success');

        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $target->id)
            ->where('role', 'database')
            ->sole();

        expect($assignment->status)
            ->toBe(NodeRoleStatus::Error)
            ->and($assignment->last_error)
            ->toBe('Docker is missing.');
    });

    it('rejects reconverge existing for non metrics roles', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'database',
            'settings' => [],
            'reconverge_existing' => true,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'reconverge_existing')
            ->assertJsonPath('error.meta.role', 'database');

        expect($target->roleAssignments()->where('role', 'database')->exists())->toBeFalse();
    });

    it('rejects gateway role additions before side effects', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'gateway',
            'settings' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', "Role 'gateway' is gateway-coupled and cannot be assigned independently.")
            ->assertJsonPath('error.meta.field', 'role')
            ->assertJsonPath('error.meta.role', 'gateway')
            ->assertJsonMissingPath('success');

        expect($target->roleAssignments()->where('role', 'gateway')->exists())->toBeFalse();
    });

    it('returns the authorized caller response shape', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'app-dev',
            'settings' => ['tld' => 'test'],
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'success' => [
                    'data' => [
                        'node',
                        'assignment' => [
                            'role',
                            'status',
                            'settings',
                            'last_error',
                            'converged_at',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.settings.tld', 'test');

        $selfGrant = NodeAccess::query()
            ->where('consumer_node_id', $target->id)
            ->where('serving_node_id', $target->id)
            ->first();

        expect($selfGrant?->permissions)->toBe(['workspace:setup'])->and($selfGrant?->custom_permissions)->toBe([]);
    });

    it('allows macos workload role additions through platform validation', function (
        string $platform,
        string $role,
        array $settings,
    ): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);
        $target->forceFill(['platform' => $platform])->save();

        app()->instance(NodeRoleBaselineConverger::class, new NodeRoleAddNoopConverger);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => $role,
            'settings' => $settings,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', $role)
            ->assertJsonPath('success.data.assignment.status', 'active');
    })->with([
        'macos app-dev' => ['macos_14', 'app-dev', ['tld' => 'test']],
        'darwin app-dev' => ['darwin', 'app-dev', ['tld' => 'test']],
        'macos database' => ['macos_14', 'database', []],
    ]);

    it('does not create agent work queue rows for opted-in macos agent-capable nodes', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);
        $target->forceFill([
            'platform' => 'darwin',
            'orbit_agent_capable' => true,
            'wireguard_address' => '10.6.0.45',
        ])->save();

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'app-dev',
            'settings' => ['tld' => 'test'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.assignment.role', 'app-dev')
            ->assertJsonMissingPath('success.data.agent_job');

        expect(Schema::hasTable('orbit_agent_jobs'))->toBeFalse();
    });

    it('does not create agent work queue rows for opted-in linux agent-capable nodes', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);
        $target->forceFill([
            'platform' => 'ubuntu',
            'orbit_agent_capable' => true,
            'wireguard_address' => '10.6.0.45',
        ])->save();

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'app-dev',
            'settings' => ['tld' => 'test'],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.assignment.role', 'app-dev')
            ->assertJsonMissingPath('success.data.agent_job');

        expect(Schema::hasTable('orbit_agent_jobs'))->toBeFalse();
    });

    it('does not return app-dev convergence jobs for macos nodes without agent capability', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);
        $target->forceFill([
            'platform' => 'macos_14',
            'orbit_agent_capable' => false,
            'wireguard_address' => '10.6.0.45',
        ])->save();

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'app-dev',
            'settings' => ['tld' => 'test'],
        ]);

        $response
            ->assertOk()
            ->assertJsonMissingPath('success.data.agent_job');

        expect(Schema::hasTable('orbit_agent_jobs'))->toBeFalse();
    });

    it('keeps ubuntu-only roles unsupported on macos workload nodes', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);
        $target->forceFill(['platform' => 'macos_14'])->save();

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'ingress',
            'settings' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', "Role 'ingress' does not support platform 'macos_14'.")
            ->assertJsonPath('error.meta.role', 'ingress')
            ->assertJsonMissingPath('success');
    });

    it('converges database role baseline on macos nodes by recording docker tool intent', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);
        $target->forceFill(['platform' => 'macos_14'])->save();

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'database',
            'settings' => [],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', 'database');

        $tool = NodeTool::query()
            ->where('node_id', $target->id)
            ->where('name', 'docker')
            ->sole();

        expect($tool->expected_state)->toBe('installed');
    });
});

final class NodeRoleAddMetricsRecordingShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'sudo systemctl is-enabled "$service"')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'exists' => false,
                    'hash' => null,
                    'enabled' => false,
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class NodeRoleAddNoopConverger extends NodeRoleBaselineConverger
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

    public function converge(Node $node, NodeRoleAssignment $assignment): void {}
}
