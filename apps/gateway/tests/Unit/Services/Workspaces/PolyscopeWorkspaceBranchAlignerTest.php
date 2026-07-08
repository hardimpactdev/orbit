<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Workspaces\PolyscopeWorkspaceBranchAligner;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('aligns a Polyscope workspace branch through the app node', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    $localTransport = new PolyscopeBranchAlignerLocalTransport(
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'adapter' => 'polyscope',
                'update' => 'host-branch',
                'workspace_path' => '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
                'branch' => 'cta',
                'renamed' => true,
            ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'adapter' => 'polyscope',
                'update' => 'workspace-branch',
                'workspace_id' => 'wt-1',
                'branch' => 'cta',
                'updated' => true,
            ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: '',
            durationMs: 1,
        ),
    );

    new PolyscopeWorkspaceBranchAligner(
        localExecutor: polyscopeBranchAlignerLocalExecutor($localTransport),
    )->align(
        node: $node,
        workspaceId: 'wt-1',
        path: '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
        name: 'cta',
    );

    expect($localTransport->calls)->toHaveCount(2);

    $hostBranchScript = $localTransport->calls[0]['script'];
    $adapterUpdateScript = $localTransport->calls[1]['script'];

    expect($hostBranchScript)
        ->toContain('internal:workspace-adapter:update')
        ->and($hostBranchScript)
        ->toContain("--adapter='polyscope'")
        ->and($hostBranchScript)
        ->toContain("--update='host-branch'")
        ->and($hostBranchScript)
        ->toContain("--workspace-path='/home/nckrtl/.polyscope/clones/6dad0913/young-bat'")
        ->and($hostBranchScript)
        ->toContain("--branch='cta'")
        ->and($hostBranchScript)
        ->toContain('--operation-token=')
        ->and($hostBranchScript)
        ->not->toContain('python3')->and($hostBranchScript)
        ->not->toContain('python -c')->and($hostBranchScript)
        ->not->toContain('sqlite3')->and($adapterUpdateScript)->toContain('internal:workspace-adapter:update')->and(
            $adapterUpdateScript,
        )->toContain("--adapter='polyscope'")->and($adapterUpdateScript)->toContain("--update='workspace-branch'")->and(
            $adapterUpdateScript,
        )->toContain("--workspace-id='wt-1'")->and($adapterUpdateScript)->toContain("--branch='cta'")->and(
            $adapterUpdateScript,
        )->toContain('--operation-token=')->and($adapterUpdateScript)
        ->not->toContain('python3')->and($adapterUpdateScript)
        ->not->toContain('python -c')->and($adapterUpdateScript)
        ->not->toContain('sqlite3');
});

it('does not leak host branch rename output when a Polyscope branch cannot be aligned', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    $secret = 'remote-host-secret';
    $localTransport = new PolyscopeBranchAlignerLocalTransport(
        new RemoteShellResult(
            exitCode: 1,
            stdout: json_encode(JsonEnvelope::failure(
                'branch_rename_failed',
                "secret {$secret}",
                ['secret' => $secret],
            ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: "stderr {$secret}",
            durationMs: 1,
        ),
    );

    try {
        new PolyscopeWorkspaceBranchAligner(
            localExecutor: polyscopeBranchAlignerLocalExecutor($localTransport),
        )->align(
            node: $node,
            workspaceId: 'wt-1',
            path: '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
            name: 'cta',
        );

        $this->fail('Expected Polyscope branch alignment to fail.');
    } catch (WorkspaceCreateFailed $exception) {
        expect($exception->getMessage())
            ->toBe('Polyscope workspace was created but could not be renamed.')
            ->and(polyscopeBranchAlignerExceptionBlob($exception))
            ->not
            ->toContain($secret)
            ->and($exception->meta)
            ->toMatchArray([
                'adapter' => 'polyscope',
                'reason' => 'branch_rename_failed',
            ]);
    }

    expect($localTransport->calls)->toHaveCount(1);
});

it('does not leak local executor output when Polyscope adapter metadata cannot be updated', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    $secret = 'remote-update-secret';
    $localTransport = new PolyscopeBranchAlignerLocalTransport(
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'adapter' => 'polyscope',
                'update' => 'host-branch',
                'workspace_path' => '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
                'branch' => 'cta',
                'renamed' => true,
            ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 1,
            stdout: json_encode(JsonEnvelope::failure(
                'update_failed',
                "secret {$secret}",
                ['secret' => $secret],
            ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: "stderr {$secret}",
            durationMs: 1,
        ),
    );

    try {
        new PolyscopeWorkspaceBranchAligner(
            localExecutor: polyscopeBranchAlignerLocalExecutor($localTransport),
        )->align(
            node: $node,
            workspaceId: 'wt-1',
            path: '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
            name: 'cta',
        );

        $this->fail('Expected Polyscope branch alignment to fail.');
    } catch (WorkspaceCreateFailed $exception) {
        expect($exception->getMessage())
            ->toBe('Polyscope workspace was created but could not be renamed.')
            ->and(polyscopeBranchAlignerExceptionBlob($exception))
            ->not
            ->toContain($secret)
            ->and($exception->meta)
            ->toMatchArray([
                'adapter' => 'polyscope',
                'reason' => 'workspace_adapter_update_failed',
                'adapter_error_code' => 'update_failed',
            ]);
    }
});

final class PolyscopeBranchAlignerLocalTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<RemoteShellResult> */
    private array $results;

    public function __construct(RemoteShellResult ...$results)
    {
        $this->results = $results;
    }

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

        return (
            array_shift($this->results) ?? new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'unexpected call',
                durationMs: 1,
            )
        );
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

function polyscopeBranchAlignerLocalExecutor(PolyscopeBranchAlignerLocalTransport $transport): RemoteLocalExecutor
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
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: 'gateway-secret',
        defaultTransportPreference: NodeTransportPreference::TransitionalSshFallback,
    );
}

function polyscopeBranchAlignerExceptionBlob(WorkspaceCreateFailed $exception): string
{
    return json_encode([
        'message' => $exception->getMessage(),
        'errorCode' => $exception->errorCode,
        'code' => $exception->getCode(),
        'meta' => $exception->meta,
        'trace' => $exception->getTraceAsString(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
