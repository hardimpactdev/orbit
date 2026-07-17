<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Data\Operations\ReleaseManifest;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\Ca\OrbitCaService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Operations\ReleaseManifestResolver;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        app(\App\Services\RemoteShell\RemoteLocalExecutor::class),
    );

    $this->webSocketBaselineStorage = sys_get_temp_dir().'/orbit-websocket-baseline-test-'.uniqid();
    mkdir($this->webSocketBaselineStorage.'/app/orbit', 0777, true);
    app()->useStoragePath($this->webSocketBaselineStorage);

    $this->webSocketBaselineShell = new WebSocketRoleBaselineTestShell;
    app()->instance(RemoteShell::class, $this->webSocketBaselineShell);
    app()->instance(ReleaseManifestResolver::class, new WebSocketRoleBaselineTestManifestResolver([]));
    $this->webSocketBaselineSelfContainedImage = false;
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => webSocketBaselineAgentResponse(
        request: $request,
        selfContainedImage: $this->webSocketBaselineSelfContainedImage,
    ));

    $this->webSocketBaselineIssued = new ArrayObject;
    app()->instance(OrbitCaService::class, new WebSocketRoleBaselineTestCa($this->webSocketBaselineIssued));
});

afterEach(function (): void {
    if (isset($this->webSocketBaselineStorage) && is_dir($this->webSocketBaselineStorage)) {
        File::deleteDirectory($this->webSocketBaselineStorage);
    }
});

it('converges websocket backend TLS material and runtime container through the role converger', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, valkeyNode: webSocketBaselineValkeyNode());

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $timingSteps = array_column(app(WebSocketRoleBaselineTiming::class)->records(), 'step');

    expect($this->webSocketBaselineIssued->getArrayCopy())
        ->toBe([
            ['host' => '10.6.0.44', 'additional_sans' => ['10.6.0.44']],
        ])
        ->and($timingSteps)
        ->toBe([
            'tools',
            'image',
            'render',
            'certificates',
            'source-files',
            'source-hash',
            'source-archive',
            'source-remote',
            'source-install',
            'container-apply',
        ])
        ->and(
            NodeTool::query()
                ->where('node_id', $node->id)
                ->where('name', 'docker')
                ->value('expected_state'),
        )
        ->toBe('installed')
        ->and($this->webSocketBaselineShell->scripts)
        ->toBe([]);
    Http::assertSent(fn (Request $request): bool => webSocketBaselineRuntimeRequestMatches(
        request: $request,
        action: 'image:is-self-contained',
    ));
    Http::assertSent(fn (Request $request): bool => webSocketBaselineRuntimeRequestMatches(
        request: $request,
        action: 'container:apply',
    ));
    Http::assertSent(fn (Request $request): bool => webSocketBaselineCertificateRequestMatches($request));
});

it('uses self-contained websocket images without installing source on the node', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, valkeyNode: webSocketBaselineValkeyNode());
    $this->webSocketBaselineSelfContainedImage = true;

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $timingSteps = array_column(app(WebSocketRoleBaselineTiming::class)->records(), 'step');

    expect($timingSteps)
        ->toBe(['tools', 'image', 'env', 'render', 'certificates', 'container-apply'])
        ->and($this->webSocketBaselineShell->scripts)
        ->toBe([]);
    Http::assertSent(fn (Request $request): bool => webSocketBaselineRuntimeRequestMatches(
        request: $request,
        action: 'image:is-self-contained',
    ));
    Http::assertSent(fn (Request $request): bool => webSocketBaselineRuntimeRequestMatches(
        request: $request,
        action: 'app-key:ensure',
    ));
    Http::assertSent(fn (Request $request): bool => webSocketBaselineRuntimeRequestMatches(
        request: $request,
        action: 'container:apply',
    ));
    Http::assertSent(fn (Request $request): bool => webSocketBaselineCertificateRequestMatches($request));
});

it('ensures the manifest websocket image before inspecting the runtime alias', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, redisNode: webSocketBaselineRedisNode());
    $this->webSocketBaselineSelfContainedImage = true;
    $image = 'ghcr.io/hardimpactdev/orbit-reverb:0.1.190-candidate-build@sha256:'.str_repeat('a', times: 64);
    $artifact = [
        'url' => 'https://artifacts.example.test/orbit-reverb-linux-amd64.tar',
        'sha256' => str_repeat('e', times: 64),
    ];
    app()->instance(ReleaseManifestResolver::class, new WebSocketRoleBaselineTestManifestResolver([
        'orbit-websocket' => $image,
    ], artifact: $artifact));

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    expect(array_column(app(WebSocketRoleBaselineTiming::class)->records(), 'step'))
        ->toBe(['tools', 'image-ensure', 'image', 'env', 'render', 'certificates', 'container-apply']);
    Http::assertSent(
        fn (Request $request): bool => (
            webSocketBaselineRuntimeRequestMatches(
                request: $request,
                action: 'image:ensure',
            )
            && json_decode((string) $request['input'], associative: true) === [
                'image' => $image,
                'artifact' => $artifact,
            ]
        ),
    );
});

it('preserves source fallback when the release manifest is unreachable', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, redisNode: webSocketBaselineRedisNode());
    app()->instance(
        ReleaseManifestResolver::class,
        new WebSocketRoleBaselineTestManifestResolver([], new ConnectionException('manifest unavailable')),
    );

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $timingSteps = array_column(app(WebSocketRoleBaselineTiming::class)->records(), 'step');

    expect($timingSteps)
        ->toContain('source-install');
    expect($timingSteps)
        ->not->toContain('image-ensure');
});

it('does not install legacy mutable manifest websocket images', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, redisNode: webSocketBaselineRedisNode());
    app()->instance(ReleaseManifestResolver::class, new WebSocketRoleBaselineTestManifestResolver([
        'orbit-websocket' => 'orbit-reverb:current',
    ]));

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    Http::assertNotSent(fn (Request $request): bool => webSocketBaselineRuntimeRequestMatches(
        request: $request,
        action: 'image:ensure',
    ));
    expect(array_column(app(WebSocketRoleBaselineTiming::class)->records(), 'step'))
        ->toContain('source-install');
});

it('starts an existing matching websocket runtime container when it is stopped', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, valkeyNode: webSocketBaselineValkeyNode());
    $container = app(WebSocketRuntimeContainerRenderer::class)->render(
        $node,
        WebSocketRoleSettings::fromArray($assignment->settings),
    );

    $this->webSocketBaselineShell->containerInspection = [
        'Config' => [
            'Labels' => [
                WebSocketRuntimeContainer::SpecHashLabel => $container->specHash(),
            ],
        ],
        'State' => [
            'Running' => false,
        ],
    ];

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    Http::assertSent(fn (Request $request): bool => webSocketBaselineRuntimeRequestMatches(
        request: $request,
        action: 'container:apply',
    ));
});

it('removes websocket runtime containers through the role converger', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, NodeRoleStatus::Active, webSocketBaselineValkeyNode());

    $this->webSocketBaselineShell->containerInspection = [
        'Config' => [
            'Labels' => [
                WebSocketRuntimeContainer::SpecHashLabel => 'old-spec',
            ],
        ],
        'State' => [
            'Running' => true,
        ],
    ];

    app(NodeRoleBaselineConverger::class)->remove($node, $assignment, purgeData: false);

    Http::assertSent(fn (Request $request): bool => webSocketBaselineRuntimeRequestMatches(
        request: $request,
        action: 'container:remove',
    ));
});

it('rejects websocket convergence on gateway nodes', function (): void {
    $node = webSocketBaselineNode();

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Gateway->value,
        'status' => NodeRoleStatus::Active->value,
    ]);

    $assignment = webSocketBaselineAssignment($node);

    expect(fn () => app(NodeRoleBaselineConverger::class)->converge($node, $assignment))
        ->toThrow(RuntimeException::class, 'The websocket role cannot be assigned to a gateway node.');
});

it('rejects websocket convergence on non-ubuntu nodes', function (): void {
    $node = webSocketBaselineNode(['platform' => 'macos_15']);
    $assignment = webSocketBaselineAssignment($node);

    expect(fn () => app(NodeRoleBaselineConverger::class)->converge($node, $assignment))
        ->toThrow(RuntimeException::class, 'The websocket role requires an Ubuntu host.');
});

it('rejects websocket convergence without a reachable host record', function (): void {
    $node = webSocketBaselineNode(['host' => '']);
    $assignment = webSocketBaselineAssignment($node);

    expect(fn () => app(NodeRoleBaselineConverger::class)->converge($node, $assignment))
        ->toThrow(RuntimeException::class, 'The websocket role requires a reachable host record.');
});

function webSocketBaselineNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'app-dev-1',
        'platform' => 'ubuntu',
        'host' => 'app-dev-1.example.com',
        'managed' => true,
        'wireguard_address' => '10.6.0.44',
        'status' => NodeStatus::Active,
    ], $overrides));
}

function webSocketBaselineAgentResponse(Request $request, bool $selfContainedImage): mixed
{
    $argv = $request['argv'] ?? null;
    $command = is_array($argv) ? $argv[0] ?? null : null;

    return match ($command) {
        'internal:site-certificate:install' => webSocketBaselineCertificateAgentResponse(),
        'internal:websocket-runtime' => webSocketBaselineRuntimeAgentResponse($request, $selfContainedImage),
        default => webSocketBaselineFailedAgentResponse((string) $command),
    };
}

function webSocketBaselineRuntimeAgentResponse(Request $request, bool $selfContainedImage): mixed
{
    $action = is_array($request['argv'] ?? null) ? $request['argv'][1] ?? null : null;
    $data = match ($action) {
        'image:ensure' => [
            'image' => 'manifest-image',
            'alias' => 'orbit-reverb:current',
            'self_contained' => true,
        ],
        'image:is-self-contained' => [
            'self_contained' => $selfContainedImage,
            'output' => $selfContainedImage ? 'true' : 'false',
        ],
        'app-key:ensure' => [
            'app_key' => 'base64:self-contained-test-key',
        ],
        'container:apply' => [
            'container' => 'orbit-websocket-app-dev-1',
            'status' => 'changed',
        ],
        'container:remove' => [
            'container' => 'orbit-websocket-app-dev-1',
            'removed' => true,
        ],
        default => [],
    };

    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => "websocket-runtime.{$action}",
        'binary' => 'orbit',
        'status' => $data === [] ? 'failed' : 'succeeded',
        'exit_code' => $data === [] ? 1 : 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => $data === [] ? '1' : '0',
            ],
        ],
    ]);
}

final class WebSocketRoleBaselineTestManifestResolver extends ReleaseManifestResolver
{
    /**
     * @param  array<string, string>  $roleImages
     */
    public function __construct(
        private readonly array $roleImages,
        private readonly ?ConnectionException $exception = null,
        private readonly ?array $artifact = null,
    ) {}

    #[\Override]
    public function resolve(): ReleaseManifest
    {
        if ($this->exception instanceof ConnectionException) {
            throw $this->exception;
        }

        $manifest = [
            'schema_version' => 1,
            'version' => '0.1.190',
            'source' => 'topology-candidate',
            'build_id' => 'test-build',
            'images' => [
                'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway@sha256:'.str_repeat('b', times: 64),
            ],
            'cli_artifacts' => [
                'linux-amd64' => [
                    'url' => 'https://artifacts.example.test/orbit-linux-x64',
                    'sha256' => str_repeat('c', times: 64),
                ],
            ],
            'agent_artifacts' => [],
            'role_images' => array_merge(['orbit-caddy' => 'caddy:2-alpine'], $this->roleImages),
        ];

        if ($this->artifact !== null) {
            $manifest['role_image_artifacts'] = ['orbit-websocket' => $this->artifact];
        }

        return ReleaseManifest::fromArray($manifest);
    }
}

function webSocketBaselineCertificateAgentResponse(): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'websocket-certificate.install',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'cert_path' => '/etc/orbit/certs/10.6.0.44.crt',
                            'key_path' => '/etc/orbit/certs/10.6.0.44.key',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ]);
}

function webSocketBaselineFailedAgentResponse(string $command): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => "unexpected.{$command}",
        'binary' => 'orbit',
        'status' => 'failed',
        'exit_code' => 1,
        'frames' => [
            [
                'type' => 'exit',
                'message' => '1',
            ],
        ],
    ]);
}

function webSocketBaselineRuntimeRequestMatches(Request $request, string $action): bool
{
    $argv = $request['argv'] ?? null;
    $operationId = match ($action) {
        'container:apply' => 'websocket-runtime-container-apply',
        'container:remove' => 'websocket-runtime-container-remove',
        default => "websocket-runtime.{$action}",
    };

    return (
        $request['binary'] === 'orbit'
        && agentPushRequestOperationIdMatchesToken($request)
        && is_array($argv)
        && ($argv[0] ?? null) === 'internal:websocket-runtime'
        && ($argv[1] ?? null) === $action
    );
}

function webSocketBaselineCertificateRequestMatches(Request $request): bool
{
    $argv = $request['argv'] ?? null;

    if (
        $request['binary'] !== 'orbit'
        || ! agentPushRequestOperationIdMatchesToken($request)
        || ! is_array($argv)
        || ($argv[0] ?? null) !== 'internal:site-certificate:install'
        || ! str_starts_with((string) ($argv[1] ?? ''), '--operation-token=')
        || ($argv[2] ?? null) !== '--json'
        || ! array_key_exists('input', $request->data())
    ) {
        return false;
    }

    /** @var mixed $input */
    $input = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

    return $input === [
        'cert_path' => '/etc/orbit/certs/10.6.0.44.crt',
        'key_path' => '/etc/orbit/certs/10.6.0.44.key',
        'cert' => 'certificate for 10.6.0.44',
        'key' => 'key for 10.6.0.44',
        'owner' => null,
    ];
}

function webSocketBaselineValkeyNode(array $overrides = []): Node
{
    $node = Node::factory()
        ->database()
        ->create(array_merge([
            'name' => 'valkey-1',
            'platform' => 'ubuntu',
            'host' => 'valkey-1.example.com',
            'wireguard_address' => '10.6.0.3',
            'status' => NodeStatus::Active,
        ], $overrides));

    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'valkey',
            'runtime_config' => ['service' => 'valkey'],
        ]);

    return $node;
}

function webSocketBaselineAssignment(
    Node $node,
    NodeRoleStatus $status = NodeRoleStatus::Pending,
    ?Node $valkeyNode = null,
): NodeRoleAssignment {
    return NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::WebSocket->value,
        'status' => $status->value,
        'settings' => ['valkey_node_id' => ($valkeyNode ?? webSocketBaselineValkeyNode())->id],
    ]);
}

readonly class WebSocketRoleBaselineTestCa extends OrbitCaService
{
    public function __construct(
        private ArrayObject $issued,
    ) {}

    /**
     * @param  list<string>  $additionalSans
     * @return array{cert: string, key: string}
     */
    #[\Override]
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $this->issued->append([
            'host' => $host,
            'additional_sans' => $additionalSans,
        ]);

        $certsDir = storage_path('app/orbit/certs');
        File::ensureDirectoryExists($certsDir);

        $certPath = "{$certsDir}/{$host}.crt";
        $keyPath = "{$certsDir}/{$host}.key";

        File::put($certPath, "certificate for {$host}");
        File::put($keyPath, "key for {$host}");

        return ['cert' => $certPath, 'key' => $keyPath];
    }
}

final class WebSocketRoleBaselineTestShell implements RemoteShell
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $containerInspection = null;

    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        if (str_contains($script, 'docker network inspect')) {
            return $this->success();
        }

        if (str_contains($script, 'docker container inspect')) {
            if ($this->containerInspection === null) {
                return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1);
            }

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode($this->containerInspection, JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            );
        }

        return $this->success();
    }

    private function success(): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
