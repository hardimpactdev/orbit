<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\ToolLogsRequest;
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

function createToolLogsJsonLocalNode(string $role = 'gateway'): Node
{
    $factory = match ($role) {
        'gateway' => Node::factory()->gateway(),
        'agent' => Node::factory()->agent(),
        default => Node::factory()->operator(),
    };

    return $factory->create([
        'name' => "tool-logs-json-{$role}",
        'host' => '10.8.0.1',
        'wireguard_address' => '10.8.0.1',
    ]);
}

function createToolLogsJsonManagedTool(string $nodeName = 'app-json-1', string $tool = 'supervisor'): Node
{
    $node = createTestAppHostNode([
        'name' => $nodeName,
        'status' => 'active',
    ]);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => $tool,
        'expected_state' => 'running',
    ]);

    return $node;
}

function configureToolLogsJsonControlGateway(): void
{
    config(['orbit.is_gateway' => false]);

    createToolLogsJsonLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.8.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

describe('tool:logs JSON renderer', function (): void {
    it('renders the explicit finite logs envelope shape', function (): void {
        createToolLogsJsonLocalNode('gateway');
        createToolLogsJsonManagedTool();
        app()->instance(RemoteShell::class, new ToolLogsJsonRecordingShell(stdout: "first line\nsecond line\n"));

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-json-1', '--lines' => '2', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta'])->toBe([])
            ->and(array_keys($payload['success']['data']['logs']))->toBe(['tool', 'node', 'lines'])
            ->and($payload['success']['data']['logs']['tool'])->toBe('supervisor')
            ->and($payload['success']['data']['logs']['node'])->toBe('app-json-1')
            ->and($payload['success']['data']['logs']['lines'])->toBe([
                ['message' => 'first line'],
                ['message' => 'second line'],
            ]);
    });

    it('reports missing required input as validation_failed with metadata', function (): void {
        createToolLogsJsonLocalNode('gateway');

        $exitCode = Artisan::call('tool:logs', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['field' => 'tool']);
    });

    it('reports unsupported log tools as validation-compatible tool errors with metadata', function (): void {
        createToolLogsJsonLocalNode('gateway');
        createToolLogsJsonManagedTool(tool: 'gh');
        $shell = new ToolLogsJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'gh', '--node' => 'app-json-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'gh',
                'action' => 'logs',
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects follow with json before opening a stream', function (): void {
        createToolLogsJsonLocalNode('gateway');
        createToolLogsJsonManagedTool();
        $shell = new ToolLogsJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-json-1', '--follow' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['field' => 'json'])
            ->and(Artisan::output())->not->toContain('first line')
            ->and($shell->scripts)->toBe([]);
    });

    it('forces non-interactive input in json mode and fails missing target with target fields metadata', function (): void {
        createToolLogsJsonLocalNode('gateway');
        createToolLogsJsonManagedTool();
        $shell = new ToolLogsJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_target_required')
            ->and($shell->scripts)->toBe([]);
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        configureToolLogsJsonControlGateway();

        MockClient::global([
            ToolLogsRequest::class => MockResponse::make(['error' => $error], $status),
        ]);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toHaveKey('error')
            ->and($payload['error']['code'])->toBe($error['code'])
            ->and($payload['error']['message'])->toBe($error['message'])
            ->and($payload['error']['meta'])->toBe($error['meta']);
    })->with([
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This node is not authorized to inspect tools.',
            'meta' => ['node' => 'app-1'],
        ], 403],
        'tool.not_found' => [[
            'code' => 'tool.not_found',
            'message' => "Tool 'supervisor' not found on node 'app-1'.",
            'meta' => ['tool' => 'supervisor', 'node' => 'app-1'],
        ], 404],
        'tool.unsupported_action' => [[
            'code' => 'tool.unsupported_action',
            'message' => "Tool 'gh' does not support logs.",
            'meta' => ['tool' => 'gh', 'action' => 'logs'],
        ], 400],
        'gateway_unavailable' => [[
            'code' => 'gateway_unavailable',
            'message' => 'Gateway cannot read tool logs.',
            'meta' => ['reason' => 'connect_timeout'],
        ], 503],
    ]);
});

final class ToolLogsJsonRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function __construct(
        private readonly string $stdout = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}
