<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Ca\OrbitCaService;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('applies app runtime containers through the agent-push local executor', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.80:9477/v1/commands' => Http::response(app_runtime_manager_agent_response('created')),
    ]);
    $node = app_runtime_manager_node();
    $container = app_runtime_manager_app_container();

    new AppRuntimeContainerManager(
        new AppRuntimeManagerUnusedTransport,
        new DockerCommandBuilder,
        app_runtime_manager_ca(),
        localExecutor: app_runtime_manager_executor(),
    )->apply($node, $container);

    Http::assertSent(
        fn (Request $request): bool => app_runtime_manager_request_matches(
            $request,
            [
                'operation_id' => 'app-runtime-container-apply',
                'kind' => 'app',
                'container_name' => 'orbit-app-docs',
                'expected_hash' => $container->specHash(),
                'config_path' => '/home/orbit/.config/orbit/apps/docs.ini',
            ],
        ),
    );
});

it('uses the resolved local executor for agent-capable app runtime nodes', function (): void {
    config()->set('app.key', app_runtime_manager_operation_secret());
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.80:9477/v1/commands' => Http::response(app_runtime_manager_agent_response('created')),
    ]);
    app()->instance(RemoteLocalExecutor::class, app_runtime_manager_executor());
    $node = app_runtime_manager_node();
    $container = app_runtime_manager_app_container();

    app(AppRuntimeContainerManager::class)->apply($node, $container);

    Http::assertSent(
        fn (Request $request): bool => app_runtime_manager_request_matches(
            $request,
            [
                'operation_id' => 'app-runtime-container-apply',
                'kind' => 'app',
                'container_name' => 'orbit-app-docs',
                'expected_hash' => $container->specHash(),
                'config_path' => '/home/orbit/.config/orbit/apps/docs.ini',
            ],
        ),
    );
});

it('applies workspace runtime containers through the agent-push local executor', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.80:9477/v1/commands' => Http::response(app_runtime_manager_agent_response('recreated')),
    ]);
    $node = app_runtime_manager_node();
    $container = app_runtime_manager_workspace_container();

    new WorkspaceRuntimeContainerManager(
        new AppRuntimeManagerUnusedTransport,
        new DockerCommandBuilder,
        app_runtime_manager_ca(),
        localExecutor: app_runtime_manager_executor(),
    )->apply($node, $container);

    Http::assertSent(
        fn (Request $request): bool => app_runtime_manager_request_matches(
            $request,
            [
                'operation_id' => 'workspace-runtime-container-apply',
                'kind' => 'workspace',
                'container_name' => 'orbit-ws-docs-feature-a',
                'expected_hash' => $container->specHash(),
                'config_path' => '/home/orbit/.config/orbit/workspaces/docs-feature-a.ini',
                'workspace_slug' => 'feature-a',
            ],
        ),
    );
});

it('uses the resolved local executor for agent-capable workspace runtime nodes', function (): void {
    config()->set('app.key', app_runtime_manager_operation_secret());
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.80:9477/v1/commands' => Http::response(app_runtime_manager_agent_response('recreated')),
    ]);
    app()->instance(RemoteLocalExecutor::class, app_runtime_manager_executor());
    $node = app_runtime_manager_node();
    $container = app_runtime_manager_workspace_container();

    app(WorkspaceRuntimeContainerManager::class)->apply($node, $container);

    Http::assertSent(
        fn (Request $request): bool => app_runtime_manager_request_matches(
            $request,
            [
                'operation_id' => 'workspace-runtime-container-apply',
                'kind' => 'workspace',
                'container_name' => 'orbit-ws-docs-feature-a',
                'expected_hash' => $container->specHash(),
                'config_path' => '/home/orbit/.config/orbit/workspaces/docs-feature-a.ini',
                'workspace_slug' => 'feature-a',
            ],
        ),
    );
});

function app_runtime_manager_node(): Node
{
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.44.0.80',
        'orbit_agent_capable' => true,
    ]);

    if (! $node instanceof Node) {
        throw new RuntimeException('Node factory did not return a node.');
    }

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->getKey(),
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array{
 *     operation_id: string,
 *     kind: string,
 *     container_name: string,
 *     expected_hash: string,
 *     config_path: string,
 *     workspace_slug?: string
 * }  $expected
 */
function app_runtime_manager_request_matches(Request $request, array $expected): bool
{
    $payload = app_runtime_manager_request_payload($request);
    $argv = app_runtime_manager_request_argv($payload);
    $input = app_runtime_manager_request_input($payload);
    $spec = app_runtime_manager_array($input['spec'] ?? null);
    $runtimeConfig = app_runtime_manager_array($input['runtime_config'] ?? null);
    $checks = [
        $request->url() === 'http://10.44.0.80:9477/v1/commands',
        ($payload['binary'] ?? null) === 'orbit',
        ($argv[0] ?? null) === 'internal:app-runtime-container',
        ($argv[1] ?? null) === 'container:apply',
        str_starts_with($argv[2] ?? '', '--operation-token='),
        ($argv[3] ?? null) === '--json',
        ($payload['operation_id'] ?? null) === $expected['operation_id'],
        ($spec['kind'] ?? null) === $expected['kind'],
        ($spec['name'] ?? null) === $expected['container_name'],
        ($spec['expected_hash'] ?? null) === $expected['expected_hash'],
        ($runtimeConfig['path'] ?? null) === $expected['config_path'],
    ];

    if (array_key_exists('workspace_slug', $expected)) {
        $checks[] = ($spec['workspace_slug'] ?? null) === $expected['workspace_slug'];
    }

    return ! in_array(needle: false, haystack: $checks, strict: true);
}

/**
 * @return array<string, mixed>
 */
function app_runtime_manager_request_payload(Request $request): array
{
    /** @var mixed $payload */
    $payload = $request->data();

    return app_runtime_manager_array($payload);
}

/**
 * @return list<string>
 */
function app_runtime_manager_request_argv(array $payload): array
{
    /** @var mixed $argv */
    $argv = $payload['argv'] ?? [];

    if (! is_array($argv)) {
        return [];
    }

    return array_values(array_filter($argv, is_string(...)));
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function app_runtime_manager_request_input(array $payload): array
{
    /** @var mixed $input */
    $input = $payload['input'] ?? null;

    if (! is_string($input)) {
        return [];
    }

    /** @var mixed $decoded */
    $decoded = json_decode($input, associative: true);

    return app_runtime_manager_array($decoded);
}

/**
 * @return array<string, mixed>
 */
function app_runtime_manager_array(mixed $value): array
{
    if (! is_array($value) || ! array_all(array_keys($value), static fn ($key) => is_string($key))) {
        return [];
    }

    /** @var array<string, mixed> $value */
    return $value;
}

function app_runtime_manager_app_container(): AppRuntimeContainer
{
    return new AppRuntimeContainer(
        name: 'orbit-app-docs',
        image: 'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: 'docs',
        runtimeUser: null,
        environment: [
            'APP_ENV' => 'local',
        ],
        mounts: [
            [
                'source' => '/home/orbit/apps/docs',
                'target' => AppRuntimeContainer::SourceTarget,
                'read_only' => false,
            ],
            [
                'source' => '/home/orbit/.config/orbit/apps/docs.ini',
                'target' => AppRuntimeContainer::PhpIniMountTarget,
                'read_only' => true,
            ],
        ],
        networkAliases: ['docs'],
        phpIni: [
            'memory_limit' => '512M',
        ],
    );
}

function app_runtime_manager_workspace_container(): WorkspaceRuntimeContainer
{
    return new WorkspaceRuntimeContainer(
        name: 'orbit-ws-docs-feature-a',
        image: 'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: 'docs',
        workspaceSlug: 'feature-a',
        environment: [
            'APP_ENV' => 'local',
        ],
        mounts: [
            [
                'source' => '/home/orbit/apps/docs/.worktrees/feature-a',
                'target' => WorkspaceRuntimeContainer::SourceTarget,
                'read_only' => false,
            ],
            [
                'source' => '/home/orbit/.config/orbit/workspaces/docs-feature-a.ini',
                'target' => WorkspaceRuntimeContainer::PhpIniMountTarget,
                'read_only' => true,
            ],
        ],
        networkAliases: ['docs-feature-a'],
        phpIni: [
            'memory_limit' => '512M',
        ],
    );
}

function app_runtime_manager_ca(): OrbitCaService
{
    return new readonly class extends OrbitCaService {
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }
    };
}

function app_runtime_manager_agent_response(string $outcome): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'app-runtime-container-apply',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' =>
                    json_encode([
                        'success' => [
                            'data' => [
                                'action' => 'container:apply',
                                'outcome' => $outcome,
                                'changed' => true,
                            ],
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR)."\n",
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ];
}

function app_runtime_manager_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: new AppRuntimeManagerUnusedTransport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: app_runtime_manager_operation_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: app_runtime_manager_operation_secret(),
    );
}

function app_runtime_manager_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @mago-expect lint:file-name
 */
final class AppRuntimeManagerUnusedTransport implements RemoteExecutor
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('SSH transport should not be called for app runtime manager actions.');
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('App runtime manager tests do not start long-running transports.');
    }
}
