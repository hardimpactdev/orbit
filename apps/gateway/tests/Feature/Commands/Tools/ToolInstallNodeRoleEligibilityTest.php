<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function createToolInstallRoleLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-install-role-{$role}",

        'host' => '10.16.0.1',
        'wireguard_address' => '10.16.0.1',
    ]);
}

function createToolInstallRoleTargetNode(string $name, array $overrides = []): Node
{
    unset($overrides['role'], $overrides['environment']);

    return Node::factory()->create(array_merge([
        'name' => $name,
        'status' => 'active',
    ], $overrides));
}

function assignToolInstallRole(Node $node, string $role, string $status = 'active'): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
    ]);
}

describe('tool:install node role eligibility', function (): void {
    it('installs postgres on a node with an active database role', function (): void {
        createToolInstallRoleLocalNode('gateway');
        $node = createToolInstallRoleTargetNode('db-1', ['role' => 'control']);
        assignToolInstallRole($node, 'database');
        $shell = new ToolInstallNodeRoleRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'postgres', '--node' => 'db-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'postgres',
                'node' => 'db-1',
                'state' => 'installed',
            ])
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeTrue()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('prompts database role nodes as interactive install targets', function (): void {
        createToolInstallRoleLocalNode('gateway');
        $node = createToolInstallRoleTargetNode('db-1', ['role' => 'control']);
        assignToolInstallRole($node, 'database');
        $shell = new ToolInstallNodeRoleRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $this->artisan('tool:install', ['tool' => 'postgres'])
            ->expectsChoice('Target node', 'db-1', ['db-1', 'db-1'])
            ->assertSuccessful();

        expect(NodeTool::query()->where('node_id', $node->id)->where('name', 'postgres')->exists())->toBeTrue()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('rejects mysql on a node without an active database role', function (): void {
        createToolInstallRoleLocalNode('gateway');
        $node = createToolInstallRoleTargetNode('web-1');
        assignToolInstallRole($node, 'app-prod');
        $shell = new ToolInstallNodeRoleRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'mysql', '--node' => 'web-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'node.role_required',
                'message' => "Tool 'mysql' requires node 'web-1' to have active role 'database'.",
                'meta' => [
                    'node' => 'web-1',
                    'required_role' => 'database',
                    'tool' => 'mysql',
                ],
            ])
            ->and(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('keeps allowing redis on a node without a database role', function (): void {
        createToolInstallRoleLocalNode('gateway');
        $node = createToolInstallRoleTargetNode('web-1');
        assignToolInstallRole($node, 'app-prod');
        $shell = new ToolInstallNodeRoleRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'redis', '--node' => 'web-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'web-1',
                'state' => 'installed',
            ])
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'redis')->exists())->toBeTrue()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('rejects postgres when the database role is present but not active', function (string $status): void {
        createToolInstallRoleLocalNode('gateway');
        $node = createToolInstallRoleTargetNode('db-1', ['role' => 'control']);
        assignToolInstallRole($node, 'database', $status);
        $shell = new ToolInstallNodeRoleRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'postgres', '--node' => 'db-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'node.role_required',
                'message' => "Tool 'postgres' requires node 'db-1' to have active role 'database'.",
                'meta' => [
                    'node' => 'db-1',
                    'required_role' => 'database',
                    'tool' => 'postgres',
                ],
            ])
            ->and(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    })->with(['pending', 'error', 'removing']);
});

final class ToolInstallNodeRoleRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
