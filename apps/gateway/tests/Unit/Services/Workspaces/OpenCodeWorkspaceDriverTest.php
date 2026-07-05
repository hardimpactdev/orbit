<?php

declare(strict_types=1);

use App\Contracts\OpenCodeClientFactory;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Workspaces\OpenCodeWorkspaceDriver;
use HardImpact\OpenCode\Data\Project as OpenCodeProject;
use HardImpact\OpenCode\Data\Session as OpenCodeSession;
use HardImpact\OpenCode\Data\Worktree as OpenCodeWorktree;
use HardImpact\OpenCode\OpenCode;
use HardImpact\OpenCode\Resources\ProjectResource;
use HardImpact\OpenCode\Resources\SessionResource;
use HardImpact\OpenCode\Resources\WorktreeResource;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates an OpenCode workspace and aligns it to the requested branch', function (): void {
    $client = new OpenCodeWorkspaceDriverTestClient(
        projectCurrentQueue: [openCodeProjectPayload(sandboxes: [])],
        worktreeListQueue: [[]],
        worktreeCreateQueue: [openCodeWorkspacePayload()],
        sessionCreateQueue: [openCodeSessionPayload()],
    );

    $driver = openCodeWorkspaceDriver($client, $transport = new OpenCodeWorkspaceDriverTestTransport);

    $result = $driver->create(openCodeWorkspaceApp(), openCodeWorkspaceNode(), 'feature-a', 'main');

    expect($result->name)
        ->toBe('feature-a')
        ->and($result->path)
        ->toBe('/srv/demo/.worktrees/feature-a')
        ->and($result->agentIde)
        ->toBe('opencode')
        ->and($result->agentIdeWorkspaceId)
        ->toBe('sess_feature_a')
        ->and($transport->calls)
        ->toHaveCount(1)
        ->and($transport->calls[0]['script'])
        ->toContain('internal:workspace-adapter:update')
        ->toContain("--adapter='opencode'")
        ->toContain("--update='host-branch'")
        ->toContain("--workspace-path='/srv/demo/.worktrees/feature-a'")
        ->toContain("--branch='feature-a'")
        ->toContain("--base-ref='main'")
        ->toContain('--operation-token=')
        ->not->toContain('git -C "$workspace_path"');

    expect($client->projectCurrentCalls)
        ->toBe(1)
        ->and($client->worktreeCreateCalls)
        ->toBe(1)
        ->and($client->sessionCreateCalls)
        ->toBe(1);
});

it('cleans up the OpenCode workspace when branch alignment fails', function (): void {
    $client = new OpenCodeWorkspaceDriverTestClient(
        projectCurrentQueue: [openCodeProjectPayload(sandboxes: [])],
        worktreeListQueue: [[]],
        worktreeCreateQueue: [openCodeWorkspacePayload()],
    );

    $driver = openCodeWorkspaceDriver(
        $client,
        $transport = new OpenCodeWorkspaceDriverTestTransport([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'reset failed', durationMs: 1),
        ]),
    );

    expect(fn () => $driver->create(openCodeWorkspaceApp(), openCodeWorkspaceNode(), 'feature-a', 'main'))
        ->toThrow(WorkspaceCreateFailed::class, 'OpenCode could not create the workspace.');

    expect($transport->calls)->toHaveCount(1);

    expect($client->projectCurrentCalls)
        ->toBe(1)
        ->and($client->worktreeCreateCalls)
        ->toBe(1)
        ->and($client->worktreeRemoveCalls)
        ->toBe(1)
        ->and($client->sessionCreateCalls)
        ->toBe(0);
});

it('recovers when OpenCode creates a workspace but returns a timeout response', function (): void {
    $client = new OpenCodeWorkspaceDriverTestClient(
        projectCurrentQueue: [openCodeProjectPayload(sandboxes: [])],
        worktreeListQueue: [[], ['/srv/demo/.worktrees/feature-a']],
        worktreeCreateQueue: [new RuntimeException('UnknownError')],
        sessionCreateQueue: [openCodeSessionPayload()],
    );

    $driver = openCodeWorkspaceDriver($client, $transport = new OpenCodeWorkspaceDriverTestTransport);

    $result = $driver->create(openCodeWorkspaceApp(), openCodeWorkspaceNode(), 'feature-a', 'main');

    expect($result->name)
        ->toBe('feature-a')
        ->and($result->path)
        ->toBe('/srv/demo/.worktrees/feature-a')
        ->and($transport->calls)
        ->toHaveCount(1);

    expect($client->projectCurrentCalls)
        ->toBe(1)
        ->and($client->worktreeCreateCalls)
        ->toBe(1)
        ->and($client->sessionCreateCalls)
        ->toBe(1);
});

function openCodeWorkspaceDriver(
    OpenCodeWorkspaceDriverTestClient $client,
    OpenCodeWorkspaceDriverTestTransport $transport,
): OpenCodeWorkspaceDriver {
    return new OpenCodeWorkspaceDriver(
        clientFactory: new OpenCodeWorkspaceDriverTestClientFactory($client),
        localExecutor: openCodeWorkspaceDriverExecutor($transport),
    );
}

function openCodeWorkspaceApp(): App
{
    return new App()->forceFill([
        'name' => 'demo',
        'path' => '/srv/demo',
    ]);
}

function openCodeWorkspaceNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'host' => '10.6.0.7',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  list<string>  $sandboxes
 * @return array<string, mixed>
 */
function openCodeProjectPayload(array $sandboxes): array
{
    return [
        'id' => 'proj_demo',
        'worktree' => '/srv/demo',
        'vcs' => 'git',
        'time' => ['created' => 1, 'updated' => 1],
        'sandboxes' => $sandboxes,
    ];
}

/**
 * @return array<string, mixed>
 */
function openCodeWorkspacePayload(): array
{
    return [
        'name' => 'feature-a',
        'branch' => 'opencode/feature-a',
        'directory' => '/srv/demo/.worktrees/feature-a',
    ];
}

/**
 * @return array<string, mixed>
 */
function openCodeSessionPayload(): array
{
    return [
        'id' => 'sess_feature_a',
        'title' => 'feature-a',
        'directory' => '/srv/demo/.worktrees/feature-a',
    ];
}

function openCodeWorkspaceDriverExecutor(OpenCodeWorkspaceDriverTestTransport $transport): RemoteLocalExecutor
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
        operationTokenSecret: 'gateway-secret',
        defaultTransportPreference: NodeTransportPreference::TransitionalSshFallback,
    );
}

final class OpenCodeWorkspaceDriverTestTransport implements RemoteExecutor
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
        throw new RuntimeException('OpenCode workspace driver test transport does not start processes.');
    }
}

final readonly class OpenCodeWorkspaceDriverTestClientFactory implements OpenCodeClientFactory
{
    public function __construct(
        private OpenCodeWorkspaceDriverTestClient $client,
    ) {}

    public function forApp(App $app): OpenCode
    {
        return $this->client;
    }
}

final class OpenCodeWorkspaceDriverTestClient extends OpenCode
{
    public int $projectCurrentCalls = 0;

    public int $worktreeCreateCalls = 0;

    public int $worktreeRemoveCalls = 0;

    public int $sessionCreateCalls = 0;

    /**
     * @param  list<array<string, mixed>>  $projectCurrentQueue
     * @param  list<list<string>>  $worktreeListQueue
     * @param  list<array<string, mixed>|Throwable>  $worktreeCreateQueue
     * @param  list<array<string, mixed>>  $sessionCreateQueue
     */
    public function __construct(
        private array $projectCurrentQueue = [],
        private array $worktreeListQueue = [],
        private array $worktreeCreateQueue = [],
        private array $sessionCreateQueue = [],
    ) {
        parent::__construct('http://opencode.test');
    }

    public function projects(): ProjectResource
    {
        return new OpenCodeWorkspaceDriverTestProjectResource($this);
    }

    public function worktrees(): WorktreeResource
    {
        return new OpenCodeWorkspaceDriverTestWorktreeResource($this);
    }

    public function sessions(): SessionResource
    {
        return new OpenCodeWorkspaceDriverTestSessionResource($this);
    }

    public function nextCurrentProject(): OpenCodeProject
    {
        $this->projectCurrentCalls++;

        return openCodeProjectFromPayload(
            array_shift($this->projectCurrentQueue) ?? openCodeProjectPayload(sandboxes: []),
        );
    }

    /**
     * @return list<string>
     */
    public function nextWorktreeList(): array
    {
        return array_shift($this->worktreeListQueue) ?? [];
    }

    public function nextCreatedWorktree(): OpenCodeWorktree
    {
        $this->worktreeCreateCalls++;
        $payload = array_shift($this->worktreeCreateQueue) ?? openCodeWorkspacePayload();

        if ($payload instanceof Throwable) {
            throw $payload;
        }

        return openCodeWorktreeFromPayload($payload);
    }

    public function removeWorktree(): bool
    {
        $this->worktreeRemoveCalls++;

        return true;
    }

    public function nextCreatedSession(): OpenCodeSession
    {
        $this->sessionCreateCalls++;

        return openCodeSessionFromPayload(array_shift($this->sessionCreateQueue) ?? openCodeSessionPayload());
    }
}

final class OpenCodeWorkspaceDriverTestProjectResource extends ProjectResource
{
    public function current(?string $directory = null): OpenCodeProject
    {
        return $this->testClient()->nextCurrentProject();
    }

    private function testClient(): OpenCodeWorkspaceDriverTestClient
    {
        $connector = $this->connector;

        if (! $connector instanceof OpenCodeWorkspaceDriverTestClient) {
            throw new RuntimeException('Unexpected OpenCode test connector.');
        }

        return $connector;
    }
}

final class OpenCodeWorkspaceDriverTestWorktreeResource extends WorktreeResource
{
    /**
     * @return list<string>
     */
    public function list(?string $directory = null): array
    {
        return $this->testClient()->nextWorktreeList();
    }

    public function create(
        ?string $name = null,
        ?string $startCommand = null,
        ?string $directory = null,
    ): OpenCodeWorktree {
        return $this->testClient()->nextCreatedWorktree();
    }

    public function remove(string $worktreeDirectory, ?string $directory = null): bool
    {
        return $this->testClient()->removeWorktree();
    }

    private function testClient(): OpenCodeWorkspaceDriverTestClient
    {
        $connector = $this->connector;

        if (! $connector instanceof OpenCodeWorkspaceDriverTestClient) {
            throw new RuntimeException('Unexpected OpenCode test connector.');
        }

        return $connector;
    }
}

final class OpenCodeWorkspaceDriverTestSessionResource extends SessionResource
{
    public function create(string $directory, ?string $title = null, ?string $parentID = null): OpenCodeSession
    {
        return $this->testClient()->nextCreatedSession();
    }

    private function testClient(): OpenCodeWorkspaceDriverTestClient
    {
        $connector = $this->connector;

        if (! $connector instanceof OpenCodeWorkspaceDriverTestClient) {
            throw new RuntimeException('Unexpected OpenCode test connector.');
        }

        return $connector;
    }
}

/**
 * @param  array<string, mixed>  $payload
 */
function openCodeProjectFromPayload(array $payload): OpenCodeProject
{
    return new OpenCodeProject(
        id: (string) $payload['id'],
        worktree: (string) $payload['worktree'],
        time: is_array($payload['time'] ?? null) ? $payload['time'] : [],
        sandboxes: is_array($payload['sandboxes'] ?? null) ? $payload['sandboxes'] : [],
        vcs: is_string($payload['vcs'] ?? null) ? $payload['vcs'] : null,
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function openCodeWorktreeFromPayload(array $payload): OpenCodeWorktree
{
    return new OpenCodeWorktree(
        name: (string) $payload['name'],
        branch: (string) $payload['branch'],
        directory: (string) $payload['directory'],
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function openCodeSessionFromPayload(array $payload): OpenCodeSession
{
    return new OpenCodeSession(
        id: (string) $payload['id'],
        title: (string) $payload['title'],
        directory: is_string($payload['directory'] ?? null) ? $payload['directory'] : null,
    );
}
