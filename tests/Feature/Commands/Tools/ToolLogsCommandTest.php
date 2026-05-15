<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\RemoteShellStream;
use App\Contracts\ToolLogGatewayStream;
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

function createToolLogsLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

describe('tool:logs command contract', function (): void {
    it('reads finite logs for a managed service tool', function (): void {
        createToolLogsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'supervisor',
            'expected_state' => 'running',
        ]);
        $shell = new ToolLogsRecordingShell(stdout: "2026-05-06 supervisor started\n2026-05-06 supervisor running\n");
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-1', '--lines' => '2', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs'])->toMatchArray([
                'tool' => 'supervisor',
                'node' => 'app-1',
                'lines' => [
                    ['message' => '2026-05-06 supervisor started'],
                    ['message' => '2026-05-06 supervisor running'],
                ],
            ])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('journalctl _SYSTEMD_UNIT=')
            ->and($shell->scripts[0])->toContain('SYSLOG_IDENTIFIER=')
            ->and($shell->scripts[0])->toContain('supervisor')
            ->and($shell->scripts[0])->toContain('--no-pager --output=short-iso')
            ->and($shell->scripts[0])->toContain('systemctl status');
    });

    it('prints finite human output as source-ordered log lines without progress or tool-state metadata', function (): void {
        createToolLogsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'supervisor',
            'expected_state' => 'running',
        ]);
        app()->instance(RemoteShell::class, new ToolLogsRecordingShell(stdout: "first line\nsecond line\n"));

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-1', '--lines' => '2']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('first line')
            ->and($output)->toContain('second line')
            ->and($output)->not->toContain('┌')
            ->and($output)->not->toContain('Resolve target')
            ->and($output)->not->toContain('Expected:')
            ->and($output)->not->toContain('Managed:')
            ->and($output)->not->toContain('Version:');
    });

    it('prints a finite human fallback when no log lines are returned', function (): void {
        createToolLogsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'supervisor',
            'expected_state' => 'running',
        ]);
        app()->instance(RemoteShell::class, new ToolLogsRecordingShell);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('No log lines found.')
            ->and($output)->not->toContain('┌')
            ->and($output)->not->toContain('Expected:')
            ->and($output)->not->toContain('Managed:');
    });

    it('rejects tools without a log source before remote work', function (): void {
        createToolLogsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'running',
        ]);
        $shell = new ToolLogsRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'gh', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'gh',
                'action' => 'logs',
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects json follow until a stream contract exists', function (): void {
        createToolLogsLocalNode('gateway');

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-1', '--follow' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toMatchArray([
                'field' => 'json',
            ]);
    });

    it('reads tool logs in follow mode for human output', function (): void {
        createToolLogsLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'supervisor',
            'expected_state' => 'running',
        ]);
        $shell = new ToolLogsRecordingShell(stdout: "followed line\n");
        app()->instance(RemoteShell::class, $shell);
        $stream = new ToolLogsRecordingStream;
        app()->instance(RemoteShellStream::class, $stream);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-1', '--lines' => '1', '--follow' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('followed line')
            ->and($shell->scripts)->toBe([])
            ->and($stream->scripts)->toHaveCount(1)
            ->and($stream->scripts[0])->toContain('journalctl _SYSTEMD_UNIT=')
            ->and($stream->scripts[0])->toContain('SYSLOG_IDENTIFIER=')
            ->and($stream->scripts[0])->toContain('-n 1 -f --no-pager --output=short-iso');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolLogsLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            ToolLogsRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'logs' => [
                            'tool' => 'supervisor',
                            'node' => 'app-1',
                            'lines' => [
                                ['message' => 'forwarded line'],
                            ],
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['logs']['lines'][0]['message'])->toBe('forwarded line');
    });

    it('streams followed logs for non-gateway callers through the gateway', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolLogsLocalNode('control');
        $stream = new ToolLogsRecordingGatewayStream;
        app()->instance(ToolLogGatewayStream::class, $stream);

        $exitCode = Artisan::call('tool:logs', ['tool' => 'supervisor', '--node' => 'app-1', '--lines' => '1', '--follow' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('forwarded followed line')
            ->and($stream->calls)->toBe([
                [
                    'tool' => 'supervisor',
                    'node' => 'app-1',
                    'app' => null,
                    'lines' => 1,
                ],
            ]);
    });
});

final class ToolLogsRecordingShell implements RemoteShell
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

final class ToolLogsRecordingStream implements RemoteShellStream
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  callable(string): void  $onOutput
     * @param  array<string, mixed>  $options
     */
    public function stream(Node $node, string $script, callable $onOutput, array $options = []): int
    {
        $this->scripts[] = $script;
        $onOutput("followed line\n");

        return 0;
    }
}

final class ToolLogsRecordingGatewayStream implements ToolLogGatewayStream
{
    /**
     * @var list<array{tool: string, node: string|null, app: string|null, lines: int}>
     */
    public array $calls = [];

    /**
     * @param  callable(string): void  $onOutput
     */
    public function follow(string $tool, ?string $node, ?string $app, int $lines, callable $onOutput): int
    {
        $this->calls[] = [
            'tool' => $tool,
            'node' => $node,
            'app' => $app,
            'lines' => $lines,
        ];

        $onOutput("forwarded followed line\n");

        return 0;
    }
}
