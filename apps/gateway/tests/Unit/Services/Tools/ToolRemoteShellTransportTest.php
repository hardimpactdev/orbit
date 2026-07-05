<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\Tools\ToolReconfigurer;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

it('requires explicit transitional ssh fallback before reconfiguring tool scripts', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-reconfigure-node']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'polyscope-server',
        'config' => [
            'port' => 9876,
        ],
    ]);
    $shell = new ToolRemoteShellTransportRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'polyscope-server',
        node: 'tool-reconfigure-node',
        config: [
            'port' => 4321,
        ],
    );

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('node_transport_required')
        ->and($result->meta['required'])
        ->toBe('transitional-ssh-fallback')
        ->and($tool->fresh()->config)
        ->toBe([
            'port' => 9876,
        ])
        ->and($shell->scripts)
        ->toBe([]);
});

it('runs reconfigure tool scripts when transitional ssh fallback is explicit', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-reconfigure-node']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'polyscope-server',
        'config' => [
            'port' => 9876,
        ],
    ]);
    $shell = new ToolRemoteShellTransportRecordingShell;
    app()->instance(RemoteShell::class, $shell);
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'polyscope-server',
        node: 'tool-reconfigure-node',
        config: [
            'port' => 4321,
        ],
    );

    expect($result)
        ->toMatchArray([
            'name' => 'polyscope-server',
            'node' => 'tool-reconfigure-node',
            'action' => 'reconfigured',
        ])
        ->and($tool->fresh()->config)
        ->toBe([
            'port' => 4321,
        ])
        ->and($shell->scripts)
        ->toHaveCount(1);
});

it('requires explicit transitional ssh fallback before bulk tool update scripts', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-update-node']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolRemoteShellTransportRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $result = app(ToolUpdater::class)->updateAll(node: 'tool-update-node');

    expect($result['updated'])
        ->toBe([])
        ->and($result['skipped'])
        ->toBe([])
        ->and($result['failed'])
        ->toHaveCount(1)
        ->and($result['failed'][0]['error'])
        ->toContain('requires explicit --node-transport=transitional-ssh-fallback')
        ->and($tool->fresh()->expected_version)
        ->toBe('old')
        ->and($shell->scripts)
        ->toBe([]);
});

it('runs bulk tool update scripts when transitional ssh fallback is explicit', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-update-node']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $shell = new ToolRemoteShellTransportRecordingShell;
    app()->instance(RemoteShell::class, $shell);
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);

    $result = app(ToolUpdater::class)->updateAll(node: 'tool-update-node');

    expect($result['updated'])
        ->toBe([
            [
                'tool' => 'node-exporter',
                'node' => 'tool-update-node',
            ],
        ])
        ->and($result['skipped'])
        ->toBe([])
        ->and($result['failed'])
        ->toBe([])
        ->and($tool->fresh()->expected_version)
        ->not
        ->toBe('old')
        ->and($shell->scripts)
        ->toHaveCount(1);
});

final class ToolRemoteShellTransportRecordingShell implements RemoteShell
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
