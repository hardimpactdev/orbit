<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\RemoveToolRequest;
use App\Http\Gateway\Requests\Tools\ToolActionStreamRequest;
use App\Models\LocalGatewaySettings;
use App\Models\LocalNodeDefault;
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

function createToolRemoveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('tool:remove command contract', function (): void {
    it('removes a managed tool after interactive confirmation without force', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        test()->artisan('tool:remove', ['tool' => 'redis', '--node' => 'app-1'])
            ->expectsConfirmation("Remove tool 'redis' from 'app-1'?", 'yes')
            ->assertSuccessful();

        expect(NodeTool::find($tool->id))->toBeNull()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('cancels interactive removal when confirmation is declined', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        test()->artisan('tool:remove', ['tool' => 'redis', '--node' => 'app-1'])
            ->expectsConfirmation("Remove tool 'redis' from 'app-1'?", 'no')
            ->expectsOutputToContain('Operation cancelled.')
            ->assertFailed();

        expect(NodeTool::find($tool->id))->not->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('bypasses interactive confirmation when force is supplied', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        test()->artisan('tool:remove', ['tool' => 'redis', '--node' => 'app-1', '--force' => true])
            ->doesntExpectOutputToContain("Remove tool 'redis' from 'app-1'?")
            ->assertSuccessful();

        expect(NodeTool::find($tool->id))->toBeNull()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('removes a managed tool with destructive consent', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'credentials' => ['password' => 'secret'],
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--node' => 'app-1', '--force' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(NodeTool::find($tool->id))->toBeNull()
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'app-1',
            ])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('docker compose')
            ->and($shell->scripts[0])->toContain('stop')
            ->and($shell->scripts[0])->toContain('rm -f');
    });

    it('treats json as destructive consent without force after ordinary validation passes', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'app-1',
            ])
            ->and(NodeTool::find($tool->id))->toBeNull()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('enforces ordinary validation before json destructive consent side effects', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--node' => 'missing-node', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('node')
            ->and(NodeTool::find($tool->id))->not->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('requires force for non-json non-interactive removal before side effects', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        test()->artisan('tool:remove redis --node=app-1 --no-interaction')
            ->expectsOutputToContain('Use --force or --json to remove this tool.')
            ->assertFailed();

        expect(NodeTool::find($tool->id))->not->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects tools without a remove action before changing intent', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'caddy', '--node' => 'app-1', '--force' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'caddy',
                'action' => 'remove',
            ])
            ->and(NodeTool::find($tool->id))->not->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('keeps retry material when remote cleanup fails', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
            'credentials' => ['fields' => ['password' => 'secret']],
        ]);
        $shell = new ToolRemoveRecordingShell(exitCode: 42, stderr: 'compose failed');
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--node' => 'app-1', '--force' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $tool->refresh();

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.remote_action_failed')
            ->and($tool->credentials)->toBe(['fields' => ['password' => 'secret']])
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('requires an explicit target source instead of falling back to the only visible app node', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--force' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['fields' => ['target']])
            ->and(NodeTool::find($tool->id))->not->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('uses local node default as an explicit target source', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        LocalNodeDefault::query()->create(['default_node_name' => 'app-1']);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-1')
            ->and(NodeTool::find($tool->id))->toBeNull()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolRemoveLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            RemoveToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'redis',
                            'node' => 'app-1',
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('redis');

        $mock->assertSent(fn (RemoveToolRequest $request): bool => $request->body()->all() === [
            'node' => 'app-1',
            'destructive_consent' => true,
            'destructive_consent_source' => 'json',
        ]);
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        config(['orbit.is_gateway' => false]);

        createToolRemoveLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            RemoveToolRequest::class => MockResponse::make(['error' => $error], $status),
        ]);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($error['code'])
            ->and($payload['error']['message'])->toBe($error['message'])
            ->and($payload['error']['meta'])->toBe($error['meta']);
    })->with([
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This node is not authorized to manage tools.',
            'meta' => ['reason' => 'missing_permission', 'missing_permission' => 'tool:remove'],
        ], 403],
        'gateway_unavailable' => [[
            'code' => 'gateway_unavailable',
            'message' => 'Gateway cannot reach the tool registry.',
            'meta' => ['reason' => 'connect_timeout'],
        ], 503],
        'tool.not_found' => [[
            'code' => 'tool.not_found',
            'message' => "Tool 'redis' was not found on node 'app-1'.",
            'meta' => ['tool' => 'redis', 'node' => 'app-1'],
        ], 404],
        'tool.remote_action_failed' => [[
            'code' => 'tool.remote_action_failed',
            'message' => "Tool 'redis' remove failed on node 'app-1'.",
            'meta' => ['tool' => 'redis', 'node' => 'app-1', 'action' => 'remove'],
        ], 502],
    ]);

    it('sends explicit consent source for streamed gateway removals', function (): void {
        $request = new ToolActionStreamRequest('remove', 'redis', [
            'node' => 'app-1',
            'destructive_consent_source' => 'force',
        ]);

        expect($request->body()->all())->toBe([
            'node' => 'app-1',
            'destructive_consent_source' => 'force',
            'destructive_consent' => true,
        ]);
    });
});

final class ToolRemoveRecordingShell implements RemoteShell
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
