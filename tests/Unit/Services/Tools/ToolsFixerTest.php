<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolsFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ToolsFixer', function (): void {
    it('starts service-backed tools when lifecycle intent expects running', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.lifecycle_state_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool caddy lifecycle state differs from gateway intent.',
            detail: [
                'tool' => 'caddy',
                'expected_state' => 'running',
                'observed_state' => 'stopped',
            ],
        ));

        expect($action)->toMatchArray([
            'family' => 'tool',
            'node' => 'app-1',
            'key' => 'tool.lifecycle_state_mismatch',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts)->toBe(['sudo systemctl start caddy']);
    });

    it('skips issue codes without catalog-declared repair commands', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
        ]);
        $shell = new ToolsFixerRemoteShell;

        $action = (new ToolsFixer($shell))->fix($tool, new DriftEntry(
            family: 'tool',
            key: 'tool.config_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Tool caddy managed configuration differs from gateway intent.',
            detail: ['tool' => 'caddy'],
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });
});

final class ToolsFixerRemoteShell implements RemoteShell
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
