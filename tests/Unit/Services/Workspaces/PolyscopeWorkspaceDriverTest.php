<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Workspaces\PolyscopeWorkspaceBranchAligner;
use App\Services\Workspaces\PolyscopeWorkspaceDriver;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('reads Polyscope config through the local executor lookup command with stdout suppressed in activity logs', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-dev',
        'role' => 'app',
        'agent_ide_config' => null,
    ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'agent_ide_config' => null,
    ]);
    $transport = new PolyscopeWorkspaceDriverTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'api_token' => 'poly-token-secret',
            'server_id' => null,
            'repository_id' => 'repo-docs',
            'base_url' => 'https://polyscope.test',
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: '',
        durationMs: 2,
    ));

    $driver = new PolyscopeWorkspaceDriver(
        branchAligner: new PolyscopeWorkspaceBranchAligner(new PolyscopeWorkspaceDriverUnusedShell),
        localExecutor: polyscopeWorkspaceDriverExecutor($transport),
    );

    try {
        $driver->create($app, $node, 'feature-docs', 'main');

        $this->fail('Expected Polyscope workspace creation to fail before creating a remote workspace.');
    } catch (WorkspaceCreateFailed $exception) {
        expect($exception->errorCode)->toBe('workspace.agent_ide_not_configured')
            ->and($exception->meta['missing'])->toBe(['server_id']);
    }

    expect($transport->calls)->toHaveCount(1);

    $script = $transport->calls[0]['script'];

    expect($script)->toContain('internal:workspace-adapter:lookup')
        ->and($script)->toContain("--adapter='polyscope'")
        ->and($script)->toContain("--lookup='config'")
        ->and($script)->toContain("--app-path='/srv/docs'")
        ->and($script)->toContain('--operation-token=')
        ->and($script)->not->toContain('python3')
        ->and($script)->not->toContain('python -c')
        ->and($script)->not->toContain('sqlite3')
        ->and($script)->not->toContain('php -r');

    $completed = polyscopeWorkspaceDriverLocalExecutorActivityRows()[1];
    $properties = json_decode((string) $completed->properties, true, flags: JSON_THROW_ON_ERROR);

    expect($properties['stdout_summary'])->toBe('<suppressed>')
        ->and(json_encode($properties, JSON_THROW_ON_ERROR))->not->toContain('poly-token-secret');
});

function polyscopeWorkspaceDriverExecutor(PolyscopeWorkspaceDriverTransport $transport): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: $transport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
    );
}

/**
 * @return list<object>
 */
function polyscopeWorkspaceDriverLocalExecutorActivityRows(): array
{
    return DB::table('activity_log')
        ->where('log_name', 'local_executor')
        ->orderBy('id')
        ->get()
        ->all();
}

final class PolyscopeWorkspaceDriverTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('The recording transport does not start processes.');
    }
}

final class PolyscopeWorkspaceDriverUnusedShell implements RemoteShell
{
    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('The unused remote shell should not run.');
    }
}
