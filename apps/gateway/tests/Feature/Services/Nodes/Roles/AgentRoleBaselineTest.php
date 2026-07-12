<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\RoleBaselines\AgentRoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        app(\App\Services\RemoteShell\RemoteLocalExecutor::class),
    );

    $this->configDir = storage_path('framework/testing/agent-dns');
    File::deleteDirectory($this->configDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->configDir);
});

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function agent_role_agent_response(string $operationId, array $data, int $exitCode = 0): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ];
}

/**
 * @return list<Request>
 */
function agent_role_agent_requests(string $wireguardAddress): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === "http://{$wireguardAddress}:9477/v1/commands",
    )
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}

function fake_agent_role_agent_convergence(string $wireguardAddress): void
{
    Http::preventStrayRequests();
    Http::fake([
        "http://{$wireguardAddress}:9477/v1/commands" => Http::sequence()
            ->push(agent_role_agent_response('agent-user.ensure', [
                'user' => 'agent',
                'created' => false,
                'locked' => true,
            ]))
            ->push(agent_role_agent_response('agent-acl.ensure', [
                'installed_acl' => false,
                'directory_acl_exit_code' => 0,
                'binary_acl_exit_code' => 0,
            ])),
    ]);
}

describe('agent role baseline', function (): void {
    it('converges caddy as a desired tool', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => 'agent',
            'managed' => true,
            'wireguard_address' => '10.6.0.50',
        ]);
        fake_agent_role_agent_convergence('10.6.0.50');

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => [],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        $tools = NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['caddy', 'supervisor'])
            ->orderBy('name')
            ->get();

        expect($tools->pluck('name')->all())
            ->toBe(['caddy'])
            ->and(
                NodeTool::query()
                    ->where('node_id', $node->id)
                    ->where('name', 'git')
                    ->value('expected_state'),
            )
            ->toBe('installed')
            ->and($tools->mapWithKeys(fn (NodeTool $tool): array => [$tool->name => $tool->expected_state])->all())
            ->toBe([
                'caddy' => 'installed',
            ]);
    });

    it('materializes a gateway-owned agent dns mapping for the tld', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => 'agent',
            'managed' => true,
            'wireguard_address' => '10.6.0.50',
        ]);
        fake_agent_role_agent_convergence('10.6.0.50');

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => [],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        expect(File::exists("{$this->configDir}/agent.conf"))->toBeTrue();
        expect(File::get("{$this->configDir}/agent.conf"))
            ->toContain('orbit-managed=node-development-dns')
            ->toContain('address=/agent/10.6.0.50');
    });

    it('converges the shared unprivileged agent user through agent-push local executor', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => 'agent',
            'managed' => true,
            'wireguard_address' => '10.6.0.50',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => [],
        ]);

        fake_agent_role_agent_convergence('10.6.0.50');

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        expect(agent_role_agent_requests('10.6.0.50'))
            ->toHaveCount(2)
            ->and(agent_role_agent_requests('10.6.0.50')[0]['argv'] ?? [])
            ->toContain('internal:agent-user:ensure')
            ->and(agent_role_agent_requests('10.6.0.50')[1]['argv'] ?? [])
            ->toContain('internal:agent-acl:ensure');
    });

    it('rejects agent convergence without a wireguard address', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => 'agent',
            'wireguard_address' => null,
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => [],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        expect(fn () => $baseline->converge($node, $assignment))
            ->toThrow(
                RuntimeException::class,
                'The agent role requires a WireGuard address so the agent DNS mapping can be materialized.',
            );
    });

    it('rejects agent convergence on gateway nodes', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => 'gateway',
            'wireguard_address' => '10.6.0.2',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Gateway->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $assignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => [],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        expect(fn () => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'The agent role cannot be assigned to a gateway node.');
    });

    it('rejects agent convergence on non-ubuntu platforms', function (): void {
        $node = Node::factory()->create([
            'platform' => 'macos_15',
            'tld' => 'agent',
            'wireguard_address' => '10.6.0.50',
        ]);

        $assignment = NodeRoleAssignment::factory()->make([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => [],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        expect(fn () => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'The agent role requires an Ubuntu host.');
    });

    it('removes agent baseline including dns mapping and tools', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu',
            'tld' => 'agent',
            'managed' => true,
            'wireguard_address' => '10.6.0.50',
        ]);
        fake_agent_role_agent_convergence('10.6.0.50');

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Active->value,
            'settings' => [],
        ]);

        $baseline = new AgentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        expect(File::exists("{$this->configDir}/agent.conf"))->toBeTrue();
        expect(NodeTool::query()->where('node_id', $node->id)->exists())->toBeTrue();

        $baseline->remove($node, $assignment, purgeData: false);

        expect(File::exists("{$this->configDir}/agent.conf"))->toBeFalse();
        expect(NodeTool::query()->where('node_id', $node->id)->exists())->toBeFalse();
    });
});

describe('agent role node-owned TLD', function (): void {
    it('preserves the node TLD when assigning the agent role during creation', function (): void {
        $node = Node::factory()->create([
            'tld' => 'agent',
            'platform' => 'ubuntu',
            'wireguard_address' => '10.0.0.12',
            'managed' => true,
        ]);

        fake_agent_role_agent_convergence('10.0.0.12');

        $assignment = app(NodeRoleAssignmentService::class)->addDuringCreation($node, 'agent', []);

        expect($assignment->settings)
            ->toBe([])
            ->and($node->fresh()->tld)
            ->toBe('agent');
    });

    it('rejects role-local TLD settings for agent and app-dev roles', function (string $role): void {
        $node = Node::factory()->create([
            'tld' => 'workload',
            'platform' => 'ubuntu',
            'wireguard_address' => '10.0.0.12',
        ]);

        expect(fn () => app(NodeRoleAssignmentService::class)->addDuringCreation($node, $role, ['tld' => 'other']))
            ->toThrow(InvalidArgumentException::class, "The {$role} role does not accept settings.");

        expect($node->roleAssignments()->where('role', $role)->exists())->toBeFalse();
    })->with(['agent', 'app-dev']);
});
