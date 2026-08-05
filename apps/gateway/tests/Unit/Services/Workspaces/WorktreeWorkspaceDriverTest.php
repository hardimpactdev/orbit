<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Workspaces\WorktreeWorkspaceDriver;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\RemoteExecutorBackedInternalExecutor;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates worktree workspaces through the local executor internal command', function (): void {
    $transport = new WorktreeWorkspaceDriverTestTransport;
    $driver = new WorktreeWorkspaceDriver(worktreeWorkspaceDriverExecutor($transport));

    $result = $driver->create(
        app: worktreeWorkspaceDriverApp(),
        node: worktreeWorkspaceDriverNode(),
        name: 'feature-docs',
        base: 'main',
    );

    expect($result->name)
        ->toBe('feature-docs')
        ->and($result->path)
        ->toBe('/srv/docs/.worktrees/feature-docs')
        ->and($transport->calls)
        ->toHaveCount(1)
        ->and($transport->calls[0]['script'])
        ->toContain('internal:workspace-source:create')
        ->toContain("'/srv/docs'")
        ->toContain("'feature-docs'")
        ->toContain("'main'")
        ->toContain('--operation-token=')
        ->not->toContain('git -C "$app_path"')
        ->not->toContain('worktree add "$relative_path"');
});

it('reports internal workspace source creation failures as workspace source failures', function (): void {
    $transport = new WorktreeWorkspaceDriverTestTransport([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'worktree failed', durationMs: 1),
    ]);
    $driver = new WorktreeWorkspaceDriver(worktreeWorkspaceDriverExecutor($transport));

    expect(fn () => $driver->create(
        app: worktreeWorkspaceDriverApp(),
        node: worktreeWorkspaceDriverNode(),
        name: 'feature-docs',
        base: 'main',
    ))
        ->toThrow(WorkspaceCreateFailed::class, 'Failed to create git worktree: worktree failed');
});

function worktreeWorkspaceDriverApp(): App
{
    return new App()->forceFill([
        'name' => 'docs',
        'path' => '/srv/docs',
    ]);
}

function worktreeWorkspaceDriverNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'app-dev',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    return $node;
}

function worktreeWorkspaceDriverExecutor(WorktreeWorkspaceDriverTestTransport $transport): RunsInternalCommands
{
    return new RemoteExecutorBackedInternalExecutor($transport);
}

final class WorktreeWorkspaceDriverTestTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results = [],
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    #[Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Worktree workspace driver test transport does not start processes.');
    }
}
