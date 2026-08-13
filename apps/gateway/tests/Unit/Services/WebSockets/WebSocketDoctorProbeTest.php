<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process as NodeProcess;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\WebSockets\WebSocketDoctorProbe;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\Fakes\RemoteExecutorBackedInternalExecutor;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('runs the valkey doctor script inside the rendered websocket runtime container without php dash r', function (): void {
    $valkeyNode = Node::factory()
        ->database()
        ->create([
            'name' => 'valkey-1',
            'status' => 'active',
            'host' => '203.0.113.10',
            'wireguard_address' => '10.6.0.10',
        ]);
    NodeProcess::factory()
        ->forOwner($valkeyNode)
        ->create([
            'name' => 'valkey',
            'runtime' => ProcessRuntime::Docker,
            'command' => 'valkey-server --appendonly yes',
            'runtime_config' => [
                'service' => 'valkey',
            ],
        ]);
    $websocketNode = Node::factory()->create([
        'name' => 'realtime-1',
        'status' => 'active',
        'host' => '203.0.113.44',
        'wireguard_address' => '10.6.0.44',
    ]);
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $websocketNode->id,
        'role' => 'websocket',
        'status' => 'active',
        'settings' => [
            'valkey_node_id' => $valkeyNode->id,
        ],
    ]);
    $shell = new WebSocketDoctorProbeTestTransport([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "exists=1\nrunning=true\nenv_host=10.6.0.44\ncmd_host=10.6.0.44\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        new RemoteExecutorBackedInternalExecutor($shell),
    );

    $drift = app(WebSocketDoctorProbe::class)->toolDrift($websocketNode, $assignment);

    $expectedContainer = app(WebSocketRuntimeContainerRenderer::class)->containerName($websocketNode);
    $valkeyScript = collect($shell->calls)
        ->pluck('script')
        ->first(fn (string $script): bool => str_contains($script, 'doctor:valkey-probe'));

    expect($drift)->toBe([])->and($valkeyScript)->toBeString();

    $valkeyScript = (string) $valkeyScript;

    expect($valkeyScript)
        ->toContain('internal:websocket-runtime')
        ->and($valkeyScript)
        ->toContain('doctor:valkey-probe')
        ->and($valkeyScript)
        ->not->toContain('# orbit-websocket-doctor:valkey-probe')->and($valkeyScript)
        ->not->toContain('docker exec -i "$container" php')->and($valkeyScript)
        ->not->toContain('php -r');

    expect($shell->calls[1]['options']['input'] ?? '')
        ->json()
        ->toBe(['container' => $expectedContainer]);
})->group('websocket', 'doctor');

it('accepts a container-wide Reverb listener behind a WireGuard-only Docker publication', function (): void {
    $websocketNode = Node::factory()->create([
        'name' => 'realtime-1',
        'status' => 'active',
        'host' => '203.0.113.44',
        'wireguard_address' => '10.6.0.44',
    ]);
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $websocketNode->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);
    $shell = new WebSocketDoctorProbeTestTransport([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "cert_exists=1\nkey_exists=1\ncert_matches=1\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 0,
            stdout: "exists=1\nrunning=true\nenv_host=0.0.0.0\ncmd_host=0.0.0.0\npublished_bindings=[{\"host\":\"10.6.0.44\",\"port\":\"8080\"}]\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        new RemoteExecutorBackedInternalExecutor($shell),
    );

    expect(app(WebSocketDoctorProbe::class)->nodeDrift($websocketNode, $assignment))->toBeEmpty();
})->group('websocket', 'doctor');

it('reports a missing WireGuard-only Docker publication', function (): void {
    $websocketNode = Node::factory()->create([
        'name' => 'realtime-1',
        'status' => 'active',
        'wireguard_address' => '10.6.0.44',
    ]);
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $websocketNode->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);
    $shell = new WebSocketDoctorProbeTestTransport([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "cert_exists=1\nkey_exists=1\ncert_matches=1\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 0,
            stdout: "exists=1\nrunning=true\nenv_host=0.0.0.0\ncmd_host=0.0.0.0\npublished_bindings=[]\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        new RemoteExecutorBackedInternalExecutor($shell),
    );

    $drift = app(WebSocketDoctorProbe::class)->nodeDrift($websocketNode, $assignment);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->key)
        ->toBe('node.websocket.bind_public_interface')
        ->and($drift[0]->kind)
        ->toBe(\App\Enums\DriftKind::Divergent)
        ->and($drift[0]->detail)
        ->toMatchArray([
            'expected_published_bindings' => [['host' => '10.6.0.44', 'port' => '8080']],
            'observed_published_bindings' => [],
        ]);
})->group('websocket', 'doctor');

it('reports a Docker publication exposed on all host interfaces', function (): void {
    $websocketNode = Node::factory()->create([
        'name' => 'realtime-1',
        'status' => 'active',
        'wireguard_address' => '10.6.0.44',
    ]);
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $websocketNode->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);
    $shell = new WebSocketDoctorProbeTestTransport([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "cert_exists=1\nkey_exists=1\ncert_matches=1\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 0,
            stdout: "exists=1\nrunning=true\nenv_host=0.0.0.0\ncmd_host=0.0.0.0\npublished_bindings=[{\"host\":\"0.0.0.0\",\"port\":\"8080\"}]\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        new RemoteExecutorBackedInternalExecutor($shell),
    );

    $drift = app(WebSocketDoctorProbe::class)->nodeDrift($websocketNode, $assignment);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->kind)
        ->toBe(\App\Enums\DriftKind::Divergent)
        ->and($drift[0]->detail['observed_published_bindings'])
        ->toBe([['host' => '0.0.0.0', 'port' => '8080']]);
})->group('websocket', 'doctor');

it('reports an additional Docker publication outside WireGuard', function (): void {
    $websocketNode = Node::factory()->create([
        'name' => 'realtime-1',
        'status' => 'active',
        'wireguard_address' => '10.6.0.44',
    ]);
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $websocketNode->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);
    $shell = new WebSocketDoctorProbeTestTransport([
        new RemoteShellResult(
            exitCode: 0,
            stdout: "cert_exists=1\nkey_exists=1\ncert_matches=1\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(
            exitCode: 0,
            stdout: "exists=1\nrunning=true\nenv_host=0.0.0.0\ncmd_host=0.0.0.0\npublished_bindings=[{\"host\":\"10.6.0.44\",\"port\":\"8080\"},{\"host\":\"0.0.0.0\",\"port\":\"8080\"}]\n",
            stderr: '',
            durationMs: 1,
        ),
    ]);
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        new RemoteExecutorBackedInternalExecutor($shell),
    );

    $drift = app(WebSocketDoctorProbe::class)->nodeDrift($websocketNode, $assignment);

    expect($drift)
        ->toHaveCount(1)
        ->and($drift[0]->kind)
        ->toBe(\App\Enums\DriftKind::Divergent)
        ->and($drift[0]->detail['observed_published_bindings'])
        ->toBe([
            ['host' => '10.6.0.44', 'port' => '8080'],
            ['host' => '0.0.0.0', 'port' => '8080'],
        ]);
})->group('websocket', 'doctor');

function websocketDoctorProbeExecutor(WebSocketDoctorProbeTestTransport $transport): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
        applicationKey: 'gateway-secret',
    );
}

final class WebSocketDoctorProbeTestTransport implements RemoteExecutor
{
    /**
     * @var list<array{node: Node, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    #[Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new \RuntimeException('Websocket doctor probe test transport does not start processes.');
    }
}
