<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Tools\ToolLogsRequest;
use App\Models\App;
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

function createToolLogsTargetLocalNode(string $role = 'gateway'): Node
{
    $factory = match ($role) {
        'gateway' => Node::factory()->gateway(),
        'agent' => Node::factory()->agent(),
        default => Node::factory()->operator(),
    };

    return $factory->create([
        'name' => "tool-logs-target-{$role}",
        'host' => '10.9.0.1',
        'wireguard_address' => '10.9.0.1',
    ]);
}

function createToolLogsTarget(string $nodeName, ?string $tld = null): Node
{
    return createTestAppHostNode([
        'name' => $nodeName,
        'status' => 'active',
        'tld' => $tld,
    ]);
}

function createToolLogsManagedTool(Node $node, string $tool = 'supervisor'): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => $tool,
        'expected_state' => 'running',
    ]);
}

describe('tool:logs target resolution', function (): void {
    it('resolves app slug selectors to the owning app node', function (): void {
        createToolLogsTargetLocalNode('gateway');
        $node = createToolLogsTarget('app-logs-slug-1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolLogsManagedTool($node);
        app()->instance(RemoteShell::class, new ToolLogsTargetRecordingShell(stdout: "slug line\n"));

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs']['node'])->toBe('app-logs-slug-1');
    });

    it('resolves app domain selectors to the owning app node', function (): void {
        createToolLogsTargetLocalNode('gateway');
        $node = createToolLogsTarget('app-logs-domain-1');
        App::factory()->create(['name' => 'docs', 'domain' => 'docs.example.com', 'node_id' => $node->id]);
        createToolLogsManagedTool($node);
        app()->instance(RemoteShell::class, new ToolLogsTargetRecordingShell(stdout: "domain line\n"));

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--app' => 'docs.example.com', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs']['node'])->toBe('app-logs-domain-1');
    });

    it('resolves app slug plus node tld selectors to the owning app node', function (): void {
        createToolLogsTargetLocalNode('gateway');
        $node = createToolLogsTarget('app-logs-tld-1', 'dev1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolLogsManagedTool($node);
        app()->instance(RemoteShell::class, new ToolLogsTargetRecordingShell(stdout: "tld line\n"));

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--app' => 'docs.dev1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs']['node'])->toBe('app-logs-tld-1');
    });

    it('allows app and node selectors when they resolve to the same node', function (): void {
        createToolLogsTargetLocalNode('gateway');
        $node = createToolLogsTarget('app-logs-match-1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolLogsManagedTool($node);
        app()->instance(RemoteShell::class, new ToolLogsTargetRecordingShell(stdout: "match line\n"));

        $exitCode = Artisan::call('tool:logs', [
            'tool' => 'supervisor',
            '--app' => 'docs',
            '--node' => 'app-logs-match-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs']['node'])->toBe('app-logs-match-1');
    });

    it('rejects app and node selectors when they resolve to different nodes before side effects', function (): void {
        createToolLogsTargetLocalNode('gateway');
        $appNode = createToolLogsTarget('app-logs-mismatch-1');
        $otherNode = createToolLogsTarget('app-logs-mismatch-2');
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        createToolLogsManagedTool($appNode);
        $shell = new ToolLogsTargetRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:logs', [
            'tool' => 'supervisor',
            '--app' => 'docs',
            '--node' => $otherNode->name,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe([
                'field' => 'app',
                'value' => 'docs',
                'node' => 'app-logs-mismatch-2',
                'resolved_node' => 'app-logs-mismatch-1',
                'reason' => 'target_mismatch',
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('uses node selectors directly', function (): void {
        createToolLogsTargetLocalNode('gateway');
        $node = createToolLogsTarget('app-logs-node-1');
        createToolLogsManagedTool($node);
        app()->instance(RemoteShell::class, new ToolLogsTargetRecordingShell(stdout: "node line\n"));

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => $node->name, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs']['node'])->toBe('app-logs-node-1');
    });

    it('returns node_target_required when no explicit target is supplied', function (): void {
        createToolLogsTargetLocalNode('gateway');

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_target_required');
    });

    it('uses gateway-known caller identity as self fallback when no explicit target or node default exists', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolLogsTargetLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.9.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ShowGatewayIdentityRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'control-self',
                        ],
                    ],
                ],
            ], 200),
            ToolLogsRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'logs' => [
                            'tool' => 'supervisor',
                            'node' => 'control-self',
                            'lines' => [
                                ['message' => 'self line'],
                            ],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs']['node'])->toBe('control-self');

        $mock->assertSent(fn (ToolLogsRequest $request): bool => $request->query()->all() === [
            'node' => 'control-self',
            'lines' => 100,
        ]);
    });

    it('does not fall back to the only visible app node without an explicit source, node default, or self identity', function (): void {
        createToolLogsTargetLocalNode('gateway');
        $node = createToolLogsTarget('app-logs-only-visible');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolLogsManagedTool($node);
        $shell = new ToolLogsTargetRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_target_required')
            ->and($shell->scripts)->toBe([]);
    });
});

final class ToolLogsTargetRecordingShell implements RemoteShell
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
