<?php

declare(strict_types=1);

use App\Actions\Processes\ShowProcessLogs;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Processes\ProcessOwnerContextResolver;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolLogReader;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolRuntimeResolver;
use App\Services\Tools\ToolScriptDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('surfaces combined stdout when remote tool log reads fail with empty stderr', function (): void {
    $node = createTestAppHostNode(['name' => 'app-logs-1', 'wireguard_address' => '10.6.0.21']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'caddy',
        'expected_state' => 'installed',
        'config' => ['container' => OrbitCaddyContainer::forPrivateNode('10.6.0.21')->spec()],
    ]);

    $executor = new class implements RunsInternalCommands {
        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(JsonEnvelope::success([
                    'exit_code' => 1,
                    'stdout' => "Error response from daemon: No such container: orbit-caddy\n",
                    'stderr' => '',
                    'duration_ms' => 12,
                ]), JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 12,
            );
        }
    };

    $reader = new ToolLogReader(
        app(ToolCatalog::class),
        app(ToolRuntimeResolver::class),
        new ToolScriptDispatcher($executor),
        app(ProcessOwnerContextResolver::class),
        app(ShowProcessLogs::class),
    );

    $result = $reader->read(tool: 'caddy', node: 'app-logs-1', lines: 50);

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('tool.remote_action_failed')
        ->and($result->meta['stderr'] ?? null)
        ->toContain('No such container: orbit-caddy');
});

it('surfaces gateway-local process output when log reads fail with empty errorOutput', function (): void {
    $gateway = createTestGatewayNode([
        'name' => 'gateway-dns-logs-fail',
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'tld' => 'gateway-dns-logs-fail',
    ]);
    NodeTool::factory()->create([
        'node_id' => $gateway->id,
        'name' => 'dns',
    ]);

    Process::fake([
        '*' => Process::result(
            output: "Error response from daemon: No such container: orbit-dns\n",
            errorOutput: '',
            exitCode: 1,
        ),
    ]);

    $result = app(ToolLogReader::class)->read(tool: 'dns', node: 'gateway-dns-logs-fail', lines: 25);

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('tool.remote_action_failed')
        ->and($result->meta['stderr'] ?? null)
        ->toContain('No such container: orbit-dns');
});
