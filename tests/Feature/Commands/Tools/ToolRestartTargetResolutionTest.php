<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Tools\RestartToolRequest;
use App\Models\App;
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

function createToolRestartTargetLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-restart-target-{$role}",
        'role' => $role,
        'host' => '10.12.0.1',
        'wireguard_address' => '10.12.0.1',
    ]);
}

function createToolRestartTarget(string $nodeName, ?string $tld = null): Node
{
    return Node::factory()->create([
        'name' => $nodeName,
        'role' => 'app',
        'status' => 'active',
        'tld' => $tld,
    ]);
}

function createToolRestartManagedTool(Node $node, string $tool = 'caddy'): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => $tool,
        'expected_state' => 'running',
    ]);
}

describe('tool:restart target resolution', function (): void {
    it('resolves app slug selectors to the owning app node', function (): void {
        createToolRestartTargetLocalNode('gateway');
        $node = createToolRestartTarget('app-restart-slug-1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolRestartManagedTool($node);
        app()->instance(RemoteShell::class, new ToolRestartTargetRecordingShell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-restart-slug-1');
    });

    it('resolves app domain selectors to the owning app node', function (): void {
        createToolRestartTargetLocalNode('gateway');
        $node = createToolRestartTarget('app-restart-domain-1');
        App::factory()->create(['name' => 'docs', 'domain' => 'docs.example.com', 'node_id' => $node->id]);
        createToolRestartManagedTool($node);
        app()->instance(RemoteShell::class, new ToolRestartTargetRecordingShell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--app' => 'docs.example.com', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-restart-domain-1');
    });

    it('resolves app slug plus node tld selectors to the owning app node', function (): void {
        createToolRestartTargetLocalNode('gateway');
        $node = createToolRestartTarget('app-restart-tld-1', 'dev1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolRestartManagedTool($node);
        app()->instance(RemoteShell::class, new ToolRestartTargetRecordingShell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--app' => 'docs.dev1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-restart-tld-1');
    });

    it('allows app and node selectors when they resolve to the same node', function (): void {
        createToolRestartTargetLocalNode('gateway');
        $node = createToolRestartTarget('app-restart-match-1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolRestartManagedTool($node);
        app()->instance(RemoteShell::class, new ToolRestartTargetRecordingShell);

        $exitCode = Artisan::call('tool:restart', [
            'tool' => 'caddy',
            '--app' => 'docs',
            '--node' => 'app-restart-match-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-restart-match-1');
    });

    it('rejects app and node selectors when they resolve to different nodes before side effects', function (): void {
        createToolRestartTargetLocalNode('gateway');
        $appNode = createToolRestartTarget('app-restart-mismatch-1');
        createToolRestartTarget('app-restart-mismatch-2');
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        createToolRestartManagedTool($appNode);
        $shell = new ToolRestartTargetRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:restart', [
            'tool' => 'caddy',
            '--app' => 'docs',
            '--node' => 'app-restart-mismatch-2',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe([
                'field' => 'app',
                'value' => 'docs',
                'node' => 'app-restart-mismatch-2',
                'resolved_node' => 'app-restart-mismatch-1',
                'reason' => 'target_mismatch',
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('uses node selectors directly', function (): void {
        createToolRestartTargetLocalNode('gateway');
        $node = createToolRestartTarget('app-restart-node-1');
        createToolRestartManagedTool($node);
        app()->instance(RemoteShell::class, new ToolRestartTargetRecordingShell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--node' => 'app-restart-node-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-restart-node-1');
    });

    it('uses local node default when no explicit target is provided', function (): void {
        createToolRestartTargetLocalNode('gateway');
        $node = createToolRestartTarget('app-restart-default-1');
        createToolRestartManagedTool($node);
        LocalNodeDefault::query()->create(['default_node_name' => 'app-restart-default-1']);
        app()->instance(RemoteShell::class, new ToolRestartTargetRecordingShell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-restart-default-1');
    });

    it('uses gateway-known caller identity as self fallback when no explicit target or node default exists', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolRestartTargetLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.12.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ShowGatewayIdentityRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'control-self',
                            'role' => 'control',
                        ],
                    ],
                ],
            ], 200),
            RestartToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'caddy',
                            'node' => 'control-self',
                            'expected_state' => 'running',
                            'observed_state' => null,
                            'version' => null,
                            'managed' => true,
                            'endpoints' => [],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('control-self');

        $mock->assertSent(fn (RestartToolRequest $request): bool => $request->body()->all() === [
            'node' => 'control-self',
        ]);
    });

    it('does not fall back to the only visible app node without an explicit source, node default, or self identity', function (): void {
        createToolRestartTargetLocalNode('gateway');
        $node = createToolRestartTarget('app-restart-only-visible');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolRestartManagedTool($node);
        $shell = new ToolRestartTargetRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['fields' => ['target']])
            ->and($shell->scripts)->toBe([]);
    });
});

final class ToolRestartTargetRecordingShell implements RemoteShell
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
