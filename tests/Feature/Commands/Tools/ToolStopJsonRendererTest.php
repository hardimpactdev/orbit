<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\StopToolRequest;
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

function createToolStopJsonLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-stop-json-{$role}",
        'role' => $role,
        'host' => '10.11.0.1',
        'wireguard_address' => '10.11.0.1',
    ]);
}

function configureToolStopJsonControlGateway(): void
{
    config(['orbit.is_gateway' => false]);

    createToolStopJsonLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.11.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

describe('tool:stop JSON renderer', function (): void {
    it('selects the JSON envelope renderer and emits the canonical tool entity', function (): void {
        createToolStopJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-stop-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);
        app()->instance(RemoteShell::class, new ToolStopJsonRecordingShell);

        $exitCode = Artisan::call('tool:stop', [
            'tool' => 'caddy',
            '--node' => 'app-json-stop-1',
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
                'node' => 'app-json-stop-1',
                'expected_state' => 'installed',
                'observed_state' => null,
                'version' => null,
                'managed' => true,
                'endpoints' => [],
            ]);
    });

    it('renders missing tool input as validation_failed with field metadata', function (): void {
        createToolStopJsonLocalNode('gateway');
        $shell = new ToolStopJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', ['--node' => 'app-json-stop-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['field' => 'tool'])
            ->and($shell->scripts)->toBe([]);
    });

    it('renders invalid tool names as validation_failed with tool metadata before side effects', function (): void {
        createToolStopJsonLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-json-stop-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolStopJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', [
            'tool' => 'unknown-tool',
            '--node' => 'app-json-stop-1',
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

    it('forces non-interactive input in JSON mode and fails missing target with target fields metadata', function (): void {
        createToolStopJsonLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-json-stop-1', 'role' => 'app', 'status' => 'active']);
        $shell = new ToolStopJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['fields' => ['target']])
            ->and($shell->scripts)->toBe([]);
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        configureToolStopJsonControlGateway();

        MockClient::global([
            StopToolRequest::class => MockResponse::make(['error' => $error], $status),
        ]);

        $exitCode = Artisan::call('tool:stop', [
            'tool' => 'caddy',
            '--node' => 'app-json-stop-1',
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
            'message' => "Tool 'caddy' was not found on node 'app-json-stop-1'.",
            'meta' => ['tool' => 'caddy', 'node' => 'app-json-stop-1'],
        ], 404],
        'tool.unsupported_action' => [[
            'code' => 'tool.unsupported_action',
            'message' => "Tool 'caddy' does not support stop.",
            'meta' => ['tool' => 'caddy', 'action' => 'stop'],
        ], 400],
        'tool.remote_action_failed' => [[
            'code' => 'tool.remote_action_failed',
            'message' => "Tool 'caddy' stop failed on node 'app-json-stop-1'.",
            'meta' => [
                'tool' => 'caddy',
                'node' => 'app-json-stop-1',
                'action' => 'stop',
                'exit_code' => 12,
                'stderr' => 'systemctl failed',
            ],
        ], 502],
    ]);
});

final class ToolStopJsonRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
