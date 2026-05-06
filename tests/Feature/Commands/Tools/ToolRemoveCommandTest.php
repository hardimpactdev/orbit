<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\RemoveToolRequest;
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

function createToolRemoveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

describe('tool:remove command contract', function (): void {
    it('removes a managed tool with destructive consent', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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

    it('requires destructive consent before side effects', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        $shell = new ToolRemoveRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('destructive_consent_required')
            ->and(NodeTool::find($tool->id))->not->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects tools without a remove action before changing intent', function (): void {
        createToolRemoveLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createToolRemoveLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
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

        $exitCode = Artisan::call('tool:remove', ['tool' => 'redis', '--node' => 'app-1', '--force' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('redis');
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
