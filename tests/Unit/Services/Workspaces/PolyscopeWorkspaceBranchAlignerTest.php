<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\Node;
use App\Services\Workspaces\PolyscopeWorkspaceBranchAligner;

it('aligns a Polyscope workspace branch through the app node', function (): void {
    $node = new Node(['name' => 'beast']);
    $shell = new PolyscopeBranchAlignerRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '{"branch":"cta"}', stderr: '', durationMs: 1),
    );

    app()->instance(RemoteShell::class, $shell);

    app(PolyscopeWorkspaceBranchAligner::class)->align(
        node: $node,
        workspaceId: 'eda4dbca',
        path: '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
        name: 'cta',
    );

    expect($shell->runs)->toHaveCount(1)
        ->and($shell->runs[0]['node'])->toBe('beast')
        ->and($shell->runs[0]['options']['metadata'])->toMatchArray([
            'ORBIT_POLYSCOPE_WORKSPACE_ID' => 'eda4dbca',
            'ORBIT_POLYSCOPE_WORKSPACE_PATH' => '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
            'ORBIT_WORKSPACE_NAME' => 'cta',
        ])
        ->and($shell->runs[0]['script'])->toContain('git', 'branch', '-m')
        ->and($shell->runs[0]['script'])->toContain('update worktrees set branch = ?, branch_renamed = 1 where id = ?');
});

it('fails when a Polyscope branch cannot be aligned', function (): void {
    $node = new Node(['name' => 'beast']);

    app()->instance(RemoteShell::class, new PolyscopeBranchAlignerRecordingShell(
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'rename failed', durationMs: 1),
    ));

    expect(fn () => app(PolyscopeWorkspaceBranchAligner::class)->align(
        node: $node,
        workspaceId: 'eda4dbca',
        path: '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
        name: 'cta',
    ))->toThrow(WorkspaceCreateFailed::class, 'Polyscope workspace was created but could not be renamed.');
});

final class PolyscopeBranchAlignerRecordingShell implements RemoteShell
{
    /** @var list<array{node: string, script: string, options: array<string, mixed>}> */
    public array $runs = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result;
    }
}
