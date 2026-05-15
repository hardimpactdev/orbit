<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\ShowToolRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolShowJsonLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-show-json-{$role}",
        'role' => $role,
        'host' => '10.8.0.1',
        'wireguard_address' => '10.8.0.1',
    ]);
}

function configureToolShowJsonControlGateway(): void
{
    config(['orbit.is_gateway' => false]);

    createToolShowJsonLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.8.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

function createToolShowJsonTool(string $nodeName = 'app-json-show-1'): NodeTool
{
    $node = Node::factory()->create(['name' => $nodeName, 'role' => 'app', 'status' => 'active']);

    return NodeTool::factory()->create([
        'name' => 'redis',
        'node_id' => $node->id,
        'expected_state' => 'running',
        'expected_version' => '7.2',
    ]);
}

describe('tool:show JSON renderer', function (): void {
    it('keeps observed state null and does not inspect live state without --live', function (): void {
        createToolShowJsonLocalNode('gateway');
        createToolShowJsonTool();

        $shell = new ToolShowJsonRecordingShell(stdout: "/usr/bin/redis-server\t7.2.4\trunning\n");
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:show', ['tool' => 'redis', '--node' => 'app-json-show-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['observed_state'])->toBeNull()
            ->and($payload['success']['data']['tool'])->not->toHaveKey('observed_version')
            ->and($shell->calls)->toBe(0);
    });

    it('populates observed state from the live probe with --live', function (): void {
        createToolShowJsonLocalNode('gateway');
        createToolShowJsonTool();

        $shell = new ToolShowJsonRecordingShell(stdout: "/usr/bin/redis-server\t7.2.4\trunning\n");
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:show', [
            'tool' => 'redis',
            '--node' => 'app-json-show-1',
            '--live' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['observed_state'])->toBe('running')
            ->and($payload['success']['data']['tool']['observed_version'])->toBe('7.2.4')
            ->and($payload['success']['data']['tool']['version'])->toBe('7.2')
            ->and($shell->calls)->toBe(1);
    });

    it('renders tool remote action failure when a live probe fails', function (): void {
        createToolShowJsonLocalNode('gateway');
        createToolShowJsonTool();

        app()->instance(RemoteShell::class, new ToolShowJsonThrowingShell('ssh timeout'));

        $exitCode = Artisan::call('tool:show', [
            'tool' => 'redis',
            '--node' => 'app-json-show-1',
            '--live' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.remote_action_failed')
            ->and($payload['error']['meta']['reason'])->toBe('probe_exception')
            ->and($payload['error']['meta']['tool'])->toBe('redis')
            ->and($payload['error']['meta']['node'])->toBe('app-json-show-1');
    });

    it('passes live=1 through the gateway request and preserves observed state from the response', function (): void {
        configureToolShowJsonControlGateway();

        $mock = MockClient::global([
            ShowToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'redis',
                            'node' => 'app-json-show-1',
                            'expected_state' => 'running',
                            'observed_state' => 'running',
                            'observed_version' => '7.2.4',
                            'version' => '7.2',
                            'managed' => true,
                            'endpoints' => [],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:show', [
            'tool' => 'redis',
            '--node' => 'app-json-show-1',
            '--live' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['observed_state'])->toBe('running')
            ->and($payload['success']['data']['tool']['observed_version'])->toBe('7.2.4');

        $mock->assertSent(fn (ShowToolRequest $request): bool => $request->query()->all() === [
            'node' => 'app-json-show-1',
            'live' => '1',
        ]);
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        configureToolShowJsonControlGateway();

        MockClient::global([
            ShowToolRequest::class => MockResponse::make(['error' => $error], $status),
        ]);

        $exitCode = Artisan::call('tool:show', ['tool' => 'redis', '--node' => 'app-json-show-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($error['code'])
            ->and($payload['error']['message'])->toBe($error['message'])
            ->and($payload['error']['meta'])->toBe($error['meta']);
    })->with([
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This node is not authorized to read the tool registry.',
            'meta' => ['caller_role' => 'control'],
        ], 403],
        'gateway_unavailable' => [[
            'code' => 'gateway_unavailable',
            'message' => 'Gateway cannot reach the tool registry.',
            'meta' => ['reason' => 'connect_timeout'],
        ], 503],
        'node.not_found' => [[
            'code' => 'node.not_found',
            'message' => "Node 'app-json-show-1' was not found.",
            'meta' => ['node' => 'app-json-show-1'],
        ], 404],
    ]);

    it('uses live inspection when the gateway API receives live=1', function (): void {
        $caller = Node::factory()->create([
            'name' => 'caller',
            'role' => 'control',
            'host' => '10.8.0.96',
            'wireguard_address' => '10.8.0.96',
        ]);
        $tool = createToolShowJsonTool();

        DB::table('node_access')->insert([
            'consumer_node_id' => $caller->id,
            'serving_node_id' => $tool->node_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shell = new ToolShowJsonRecordingShell(stdout: "/usr/bin/redis-server\t7.2.4\trunning\n");
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('GET', '/api/tools/redis?node=app-json-show-1&live=1', [], [], [], ['REMOTE_ADDR' => '10.8.0.96']);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.observed_state', 'running')
            ->assertJsonPath('success.data.tool.observed_version', '7.2.4');

        expect($shell->calls)->toBe(1);
    });
});

final class ToolShowJsonRecordingShell implements RemoteShell
{
    public int $calls = 0;

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stdout = '',
        private readonly string $stderr = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls++;

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: $this->stdout,
            stderr: $this->stderr,
            durationMs: 1,
        );
    }
}

final readonly class ToolShowJsonThrowingShell implements RemoteShell
{
    public function __construct(private string $message) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException($this->message);
    }
}
