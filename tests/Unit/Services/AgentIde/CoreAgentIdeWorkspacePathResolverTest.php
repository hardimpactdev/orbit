<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Services\AgentIde\CoreAgentIdeWorkspacePathResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('passes app path context to opencode workspace resolution', function (): void {
    $node = Node::factory()->create(['role' => 'app']);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
    ]);
    $remoteShell = new CoreAgentIdeWorkspacePathResolverShell;

    $resolution = (new CoreAgentIdeWorkspacePathResolver($remoteShell))
        ->resolve('opencode', $app, '/tmp/opencode/docs-worktree');

    expect($resolution?->appSlug)->toBe('docs')
        ->and($remoteShell->options['metadata'])->toMatchArray([
            'ORBIT_WORKSPACE_PATH' => '/tmp/opencode/docs-worktree',
            'ORBIT_APP_PATH' => '/srv/docs',
        ]);
});

final class CoreAgentIdeWorkspacePathResolverShell implements RemoteShell
{
    /** @var array<string, mixed> */
    public array $options = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->options = $options;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'match' => true,
                'workspace_name' => 'docs-worktree',
                'path' => '/tmp/opencode/docs-worktree',
                'adapter_workspace_id' => 'wrk_docs',
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }
}
