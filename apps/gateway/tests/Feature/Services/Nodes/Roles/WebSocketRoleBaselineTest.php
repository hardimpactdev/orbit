<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Ca\OrbitCaService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->webSocketBaselineStorage = sys_get_temp_dir().'/orbit-websocket-baseline-test-'.uniqid();
    mkdir($this->webSocketBaselineStorage.'/app/orbit', 0777, true);
    app()->useStoragePath($this->webSocketBaselineStorage);

    $this->webSocketBaselineShell = new WebSocketRoleBaselineTestShell;
    app()->instance(RemoteShell::class, $this->webSocketBaselineShell);

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
    $assignment = webSocketBaselineAssignment($node, redisNode: webSocketBaselineRedisNode());

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $scripts = implode("\n---\n", $this->webSocketBaselineShell->scripts);

    expect($this->webSocketBaselineIssued->getArrayCopy())->toBe([
        ['host' => 'ws-1.websocket.orbit', 'additional_sans' => ['10.6.0.44']],
    ])
        ->and($scripts)->toContain("sudo install -d -m 0755 '/etc/orbit/certs'")
        ->and($scripts)->toContain('release_dir="${runtime_root}/releases/')
        ->and($scripts)->toContain('sudo install -d -m 0755 "$release_dir"')
        ->and($scripts)->toContain("'orbit-runtime:current' 'composer' 'install'")
        ->and($scripts)->toContain("docker network inspect 'orbit-network'")
        ->and($scripts)->toContain("docker container inspect --format '{{json .}}' 'orbit-websocket-ws-1'")
        ->and($scripts)->toContain("docker run -d --pull 'never' --name 'orbit-websocket-ws-1'")
        ->and($scripts)->toContain("--label 'orbit.container.kind=websocket-runtime'")
        ->and($scripts)->toContain("--network-alias 'ws-1.websocket.orbit'")
        ->and($scripts)->toContain("--env 'REVERB_SERVER_HOST=10.6.0.44'")
        ->and($scripts)->toContain("--env 'REDIS_HOST=10.6.0.3'")
        ->and($scripts)->toContain('php artisan reverb:start --host=10.6.0.44 --port=8080 --hostname=ws-1.websocket.orbit')
        ->and($scripts)->not->toContain('REVERB_SERVER_HOST=0.0.0.0');
});

it('starts an existing matching websocket runtime container when it is stopped', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, redisNode: webSocketBaselineRedisNode());
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

    expect($this->webSocketBaselineShell->scripts)->toContain("docker start 'orbit-websocket-ws-1'");
});

it('removes websocket runtime containers through the role converger', function (): void {
    $node = webSocketBaselineNode();
    $assignment = webSocketBaselineAssignment($node, NodeRoleStatus::Active, webSocketBaselineRedisNode());

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

    expect($this->webSocketBaselineShell->scripts)->toContain("docker rm -f 'orbit-websocket-ws-1'");
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
        'name' => 'ws-1',
        'platform' => 'ubuntu',
        'host' => 'ws-1.example.com',
        'wireguard_address' => '10.6.0.44',
        'status' => Node::STATUS_ACTIVE,
    ], $overrides));
}

function webSocketBaselineRedisNode(array $overrides = []): Node
{
    $node = Node::factory()->database()->create(array_merge([
        'name' => 'redis-1',
        'platform' => 'ubuntu',
        'host' => 'redis-1.example.com',
        'wireguard_address' => '10.6.0.3',
        'status' => Node::STATUS_ACTIVE,
    ], $overrides));

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'expected_state' => 'running',
    ]);

    return $node;
}

function webSocketBaselineAssignment(Node $node, NodeRoleStatus $status = NodeRoleStatus::Pending, ?Node $redisNode = null): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::WebSocket->value,
        'status' => $status->value,
        'settings' => ['redis_node_id' => ($redisNode ?? webSocketBaselineRedisNode())->id],
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
