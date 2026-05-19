<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Tools\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function createAgentToolInstallLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "agent-tool-{$role}",
        'role' => $role,
        'host' => '10.26.0.1',
        'wireguard_address' => '10.26.0.1',
    ]);
}

function createAgentToolInstallTargetNode(string $name, array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => $name,
        'role' => 'control',
        'status' => 'active',
    ], $overrides));
}

function assignAgentRole(Node $node, string $status = 'active'): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'agent',
        'status' => $status,
    ]);
}

describe('tool catalog supports agent tools', function (): void {
    it('registers openclaw in the catalog', function (): void {
        $catalog = app(ToolCatalog::class);
        $metadata = $catalog->probeMetadata('openclaw');

        expect($catalog->supports('openclaw'))->toBeTrue()
            ->and($catalog->requiredNodeRole('openclaw'))->toBe('agent')
            ->and($catalog->category('openclaw'))->toBe('agent')
            ->and($catalog->installScript('openclaw'))->toContain('sudo -u agent -H bash -lc')
            ->and($catalog->installScript('openclaw'))->toContain('https://openclaw.ai/install.sh')
            ->and($catalog->installScript('openclaw'))->toContain('--no-onboard')
            ->and($catalog->updateScript('openclaw'))->toContain('npm install -g openclaw@latest')
            ->and($metadata)->toMatchArray([
                'binary' => 'openclaw',
                'service' => 'openclaw',
                'update_command' => $catalog->updateScript('openclaw'),
            ])
            ->and($metadata['version_command'])->toContain('openclaw --version')
            ->and($metadata['repair_commands']['lifecycle_restarted'])->toContain('openclaw restart');
    });

    it('registers hermes in the catalog', function (): void {
        $catalog = app(ToolCatalog::class);
        $metadata = $catalog->probeMetadata('hermes');

        expect($catalog->supports('hermes'))->toBeTrue()
            ->and($catalog->requiredNodeRole('hermes'))->toBe('agent')
            ->and($catalog->category('hermes'))->toBe('agent')
            ->and($catalog->installScript('hermes'))->toContain('sudo -u agent -H bash -lc')
            ->and($catalog->installScript('hermes'))->toContain('https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh')
            ->and($catalog->installScript('hermes'))->toContain('--skip-setup')
            ->and($catalog->updateScript('hermes'))->toContain('hermes update')
            ->and($metadata)->toMatchArray([
                'binary' => 'hermes',
                'service' => 'hermes',
                'update_command' => $catalog->updateScript('hermes'),
            ])
            ->and($metadata['version_command'])->toContain('hermes --version')
            ->and($metadata['repair_commands']['lifecycle_restarted'])->toContain('hermes restart');
    });
});

describe('tool:install agent tool eligibility', function (): void {
    it('installs openclaw on a node with an active agent role', function (): void {
        createAgentToolInstallLocalNode('gateway');
        $node = createAgentToolInstallTargetNode('agent-1', ['role' => 'control']);
        assignAgentRole($node);
        $shell = new AgentToolInstallRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'openclaw', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'openclaw',
                'node' => 'agent-1',
                'state' => 'installed',
            ])
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'openclaw')->exists())->toBeTrue()
            ->and($shell->scripts)->toHaveCount(2)
            ->and($shell->scripts[0])->toContain('openclaw')
            ->and($shell->scripts[1])->toContain('cat');
    });

    it('installs hermes on a node with an active agent role', function (): void {
        createAgentToolInstallLocalNode('gateway');
        $node = createAgentToolInstallTargetNode('agent-1', ['role' => 'control']);
        assignAgentRole($node);
        $shell = new AgentToolInstallRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'hermes', '--node' => 'agent-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'hermes',
                'node' => 'agent-1',
                'state' => 'installed',
            ])
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'hermes')->exists())->toBeTrue()
            ->and($shell->scripts)->toHaveCount(2)
            ->and($shell->scripts[0])->toContain('hermes')
            ->and($shell->scripts[1])->toContain('cat');
    });

    it('rejects openclaw on a node without an active agent role', function (): void {
        createAgentToolInstallLocalNode('gateway');
        $node = createAgentToolInstallTargetNode('web-1');
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-production',
            'status' => 'active',
        ]);
        $shell = new AgentToolInstallRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'openclaw', '--node' => 'web-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'node.role_required',
                'message' => "Tool 'openclaw' requires node 'web-1' to have active role 'agent'.",
                'meta' => [
                    'node' => 'web-1',
                    'required_role' => 'agent',
                    'tool' => 'openclaw',
                ],
            ])
            ->and(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });
});

final class AgentToolInstallRecordingShell implements RemoteShell
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
