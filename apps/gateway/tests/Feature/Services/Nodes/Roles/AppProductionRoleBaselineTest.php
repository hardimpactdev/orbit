<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolScriptDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function appProdBaselineNode(): Node
{
    return Node::factory()->create([
        'platform' => 'ubuntu',
        'host' => '10.6.0.30',
        'wireguard_address' => '10.6.0.30',
        'status' => NodeStatus::Active,
    ]);
}

function appProdBaselineAssignment(Node $node): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => NodeRoleName::AppProduction->value,
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);
}

describe('AppProductionRoleBaseline host toolchain', function (): void {
    it('converges php-cli with expected_state installed and standard variant', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php-cli')
            ->first();

        expect($tool)
            ->not
            ->toBeNull()
            ->and($tool->expected_state)
            ->toBe('installed')
            ->and($tool->config['variant'] ?? null)
            ->toBe('standard');
    });

    it('converges composer with expected_state installed', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'composer')
            ->first();

        expect($tool)
            ->not
            ->toBeNull()
            ->and($tool->expected_state)
            ->toBe('installed');
    });

    it('converges bun as a managed-user prerequisite', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        new AppProductionRoleBaseline()->converge($node, $assignment);

        $tool = NodeTool::query()->where('node_id', $node->id)->where('name', 'bun')->first();

        expect($tool)
            ->not
            ->toBeNull()
            ->and($tool->expected_state)
            ->toBe('installed')
            ->and($tool->config)
            ->toMatchArray(['managed_user' => 'orbit']);
    });

    it('converges git with expected_state installed', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'git')
            ->first();

        expect($tool)
            ->not
            ->toBeNull()
            ->and($tool->expected_state)
            ->toBe('installed');
    });

    it('converges gh with expected_state installed', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'gh')
            ->first();

        expect($tool)
            ->not
            ->toBeNull()
            ->and($tool->expected_state)
            ->toBe('installed');
    });

    it('removes stale laravel-installer intent instead of converging it', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'laravel-installer',
            'expected_state' => 'installed',
        ]);
        $executor = new class implements RunsInternalCommands {
            /**
             * @var list<array{transport: mixed, script: string}>
             */
            public array $calls = [];

            public function runInternal(
                Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                $payload = json_decode(
                    (string) ($transportOptions['input'] ?? ''),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );

                $this->calls[] = [
                    'transport' => $transportOptions['transport'] ?? null,
                    'script' => is_array($payload) && is_string($payload['script'] ?? null) ? $payload['script'] : '',
                ];

                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: json_encode([
                        'success' => [
                            'data' => [
                                'exit_code' => 0,
                                'stdout' => '',
                                'stderr' => '',
                                'duration_ms' => 1,
                            ],
                            'meta' => (object) [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    stderr: '',
                    durationMs: 1,
                );
            }
        };
        app()->instance(RunsInternalCommands::class, $executor);
        app()->forgetInstance(ToolScriptDispatcher::class);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        expect(
            NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', 'laravel-installer')
                ->exists(),
        )
            ->toBeFalse()
            ->and($executor->calls)
            ->toHaveCount(1)
            ->and($executor->calls[0]['transport'])
            ->toBeNull()
            ->and($executor->calls[0]['script'])
            ->toContain('composer global remove laravel/installer');
    });

    it('does not converge the legacy php runtime tool row', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        expect(
            NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', 'php')
                ->exists(),
        )->toBeFalse();
    });

    it('removes host toolchain rows on role removal', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        expect(
            NodeTool::query()
                ->where('node_id', $node->id)
                ->whereIn('name', ['php-cli', 'composer', 'laravel-installer', 'gh', 'git'])
                ->count(),
        )
            ->toBe(4);

        $baseline->remove($node, $assignment, purgeData: false);

        expect(
            NodeTool::query()
                ->where('node_id', $node->id)
                ->whereIn('name', ['php-cli', 'composer', 'laravel-installer', 'gh', 'git'])
                ->count(),
        )
            ->toBe(0);
    });

    it('rejects convergence on gateway nodes', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Gateway->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $baseline = new AppProductionRoleBaseline;

        expect(fn () => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'The app-prod role cannot be assigned to a gateway node.');
    });
});
