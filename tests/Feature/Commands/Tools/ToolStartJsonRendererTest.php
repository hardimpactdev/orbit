<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\StartToolRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolStartJsonLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-start-json-{$role}",
        'role' => $role,
        'host' => '10.11.0.1',
        'wireguard_address' => '10.11.0.1',
    ]);
}

function configureToolStartJsonControlGateway(): void
{
    config(['orbit.is_gateway' => false]);

    createToolStartJsonLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.11.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

function createToolStartJsonTool(string $nodeName = 'app-json-start-1', string $tool = 'caddy'): NodeTool
{
    $node = Node::factory()->create(['name' => $nodeName, 'role' => 'app', 'status' => 'active']);

    return NodeTool::factory()->create([
        'name' => $tool,
        'node_id' => $node->id,
        'expected_state' => 'installed',
        'expected_version' => '2.8',
    ]);
}

describe('tool:start JSON renderer', function (): void {
    it('selects the JSON envelope renderer and emits the canonical tool entity', function (): void {
        createToolStartJsonLocalNode('gateway');
        createToolStartJsonTool();
        app()->instance(RemoteShell::class, new ToolStartJsonRecordingShell);

        $exitCode = Artisan::call('tool:start', [
            'tool' => 'caddy',
            '--node' => 'app-json-start-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta'])->toBe([])
            ->and(array_keys($payload['success']['data']['tool']))->toBe([
                'name',
                'node',
                'expected_state',
                'observed_state',
                'version',
                'managed',
                'endpoints',
            ])
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'caddy',
                'node' => 'app-json-start-1',
                'expected_state' => 'running',
                'observed_state' => null,
                'version' => '2.8',
                'managed' => true,
                'endpoints' => [],
            ]);
    });

    it('renders missing tool input as validation_failed', function (): void {
        createToolStartJsonLocalNode('gateway');
        app()->instance(RemoteShell::class, new ToolStartJsonRecordingShell);

        $exitCode = Artisan::call('tool:start', ['--node' => 'app-json-start-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['field' => 'tool']);
    });

    it('renders invalid tool names as validation failures before side effects', function (): void {
        createToolStartJsonLocalNode('gateway');
        Node::factory()->create(['name' => 'app-json-start-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolStartJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:start', [
            'tool' => 'unknown-tool',
            '--node' => 'app-json-start-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe([
                'field' => 'tool',
                'value' => 'unknown-tool',
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('requires a target source in non-interactive JSON mode before side effects', function (): void {
        createToolStartJsonLocalNode('gateway');
        createToolStartJsonTool();
        $shell = new ToolStartJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:start', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['fields' => ['target']])
            ->and($shell->scripts)->toBe([]);
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        configureToolStartJsonControlGateway();

        MockClient::global([
            StartToolRequest::class => MockResponse::make(['error' => $error], $status),
        ]);

        $exitCode = Artisan::call('tool:start', [
            'tool' => 'caddy',
            '--node' => 'app-json-start-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($error['code'])
            ->and($payload['error']['message'])->toBe($error['message'])
            ->and($payload['error']['meta'])->toBe($error['meta']);
    })->with([
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This node is not authorized to manage tools.',
            'meta' => ['caller_role' => 'control'],
        ], 403],
        'tool.not_found' => [[
            'code' => 'tool.not_found',
            'message' => "Tool 'caddy' was not found on node 'app-json-start-1'.",
            'meta' => ['tool' => 'caddy', 'node' => 'app-json-start-1'],
        ], 404],
        'tool.unsupported_action' => [[
            'code' => 'tool.unsupported_action',
            'message' => "Tool 'gh' does not support start.",
            'meta' => ['tool' => 'gh', 'action' => 'start'],
        ], 400],
        'tool.remote_action_failed' => [[
            'code' => 'tool.remote_action_failed',
            'message' => "Tool 'caddy' start failed on node 'app-json-start-1'.",
            'meta' => [
                'tool' => 'caddy',
                'node' => 'app-json-start-1',
                'action' => 'start',
                'exit_code' => 7,
                'stderr' => 'systemctl failed',
            ],
        ], 502],
    ]);

    it('preserves remote action exit code and stderr from local failures', function (): void {
        createToolStartJsonLocalNode('gateway');
        createToolStartJsonTool();
        app()->instance(RemoteShell::class, new ToolStartJsonRecordingShell(
            exitCode: 7,
            stderr: 'systemctl failed',
        ));

        $exitCode = Artisan::call('tool:start', [
            'tool' => 'caddy',
            '--node' => 'app-json-start-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.remote_action_failed')
            ->and($payload['error']['meta'])->toBe([
                'tool' => 'caddy',
                'node' => 'app-json-start-1',
                'action' => 'start',
                'exit_code' => 7,
                'stderr' => 'systemctl failed',
            ]);
    });
});

final class ToolStartJsonRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: '', stderr: $this->stderr, durationMs: 1);
    }
}
