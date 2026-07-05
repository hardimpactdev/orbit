<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process as NodeProcess;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\NodeCommandTransport\NodeTransportPreference;
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
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('runs the redis doctor script inside the rendered websocket runtime container without php dash r', function (): void {
    $redisNode = Node::factory()
        ->database()
        ->create([
            'name' => 'redis-1',
            'status' => 'active',
            'host' => '203.0.113.10',
            'wireguard_address' => '10.6.0.10',
        ]);
    NodeProcess::factory()
        ->forOwner($redisNode)
        ->create([
            'name' => 'redis',
            'runtime' => ProcessRuntime::Docker,
            'command' => 'redis-server --appendonly yes',
            'runtime_config' => [
                'service' => 'redis',
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
            'redis_node_id' => $redisNode->id,
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
    app()->instance(RemoteLocalExecutor::class, websocketDoctorProbeExecutor($shell));

    $drift = app(WebSocketDoctorProbe::class)->toolDrift($websocketNode, $assignment);

    $expectedContainer = app(WebSocketRuntimeContainerRenderer::class)->containerName($websocketNode);
    $redisScript = collect($shell->calls)
        ->pluck('script')
        ->first(fn (string $script): bool => str_contains($script, 'doctor:redis-probe'));

    expect($drift)->toBe([])->and($redisScript)->toBeString();

    $redisScript = (string) $redisScript;

    expect($redisScript)
        ->toContain('internal:websocket-runtime')
        ->and($redisScript)
        ->toContain('doctor:redis-probe')
        ->and($redisScript)
        ->not->toContain('# orbit-websocket-doctor:redis-probe')->and($redisScript)
        ->not->toContain('docker exec -i "$container" php')->and($redisScript)
        ->not->toContain('php -r');

    expect($shell->calls[1]['options']['input'] ?? '')
        ->json()
        ->toBe(['container' => $expectedContainer]);
})->group('websocket', 'doctor');

function websocketDoctorProbeExecutor(WebSocketDoctorProbeTestTransport $transport): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: $transport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        operationTokenSecret: 'gateway-secret',
        defaultTransportPreference: NodeTransportPreference::TransitionalSshFallback,
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
