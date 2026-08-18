<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use App\Services\WebSockets\WebSocketRuntimeSourceInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('installs the WebSocket Reverb runtime source through the agent-push local executor', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.44:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'websocket-runtime-source-install',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'success' => [
                            'data' => [
                                'action' => 'source:install',
                                'stdout' => implode("\n", [
                                    '__orbit_websocket_source_timing setup 1',
                                    '__orbit_websocket_source_timing extract 2',
                                    '__orbit_websocket_source_timing env 3',
                                    '__orbit_websocket_source_timing composer 4',
                                    '__orbit_websocket_source_timing activate 5',
                                ]),
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
                [
                    'type' => 'exit',
                    'message' => '0',
                ],
            ],
        ]),
    ]);
    $node = Node::factory()
        ->withActiveRole('websocket')
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.44',
        ]);

    new WebSocketRuntimeSourceInstaller(websocket_runtime_source_installer_executor())->install($node);

    $timingSteps = array_column(app(WebSocketRoleBaselineTiming::class)->records(), 'step');

    Http::assertSent(function (Request $request): bool {
        $input = json_decode((string) $request['input'], associative: true);

        return (
            $request->url() === 'http://10.6.0.44:9477/v1/commands'
            && $request['binary'] === 'orbit'
            && agentPushRequestOperationIdMatchesToken($request)
            && $request['timeout_seconds'] === 360
            && $request['stream'] === true
            && $request['argv'][0] === 'internal:websocket-runtime'
            && $request['argv'][1] === 'source:install'
            && str_starts_with((string) $request['argv'][2], '--operation-token=')
            && $request['argv'][3] === '--json'
            && is_array($input)
            && is_string($input['source_hash'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $input['source_hash']) === 1
            && is_string($input['archive_base64'] ?? null)
            && base64_decode($input['archive_base64'], strict: true) !== false
        );
    });

    expect($timingSteps)
        ->toContain(
            'source-files',
        )
        ->and($timingSteps)
        ->toContain('source-hash')
        ->and($timingSteps)
        ->toContain('source-archive')
        ->and(
            $timingSteps,
        )
        ->toContain('source-remote')
        ->and($timingSteps)
        ->toContain('source-composer');
});

it('ships a bootable Laravel Reverb source artifact without committed vendor files', function (): void {
    $sourcePath = repo_path('apps/reverb');
    $committedVendorFiles = Process::path(repo_path())->run(['git', 'ls-files', '-z', '--', 'apps/reverb/vendor']);

    expect("{$sourcePath}/artisan")
        ->toBeFile()
        ->and("{$sourcePath}/bootstrap/app.php")
        ->toBeFile()
        ->and("{$sourcePath}/composer.json")
        ->toBeFile()
        ->and("{$sourcePath}/composer.lock")
        ->toBeFile()
        ->and("{$sourcePath}/config/reverb.php")
        ->toBeFile();

    expect($committedVendorFiles->successful())
        ->toBeTrue()
        ->and(collect(explode("\0", $committedVendorFiles->output()))->filter()->values()->all())
        ->toBeEmpty();

    expect(file_get_contents("{$sourcePath}/config/reverb.php"))->toContain('ORBIT_WEBSOCKET_APPS_CONFIG');

    $composer = json_decode(file_get_contents("{$sourcePath}/composer.json") ?: '', true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'])->toMatchArray([
        'php' => '^8.5',
        'laravel/framework' => '^13.0',
        'laravel/reverb' => '^1.10',
    ]);
});

it('defers fallback source path validation until source install runs', function (): void {
    $executor = websocket_runtime_source_installer_executor();

    expect(fn () => new WebSocketRuntimeSourceInstaller($executor, sourcePath: '/missing/orbit-reverb'))
        ->not
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new WebSocketRuntimeSourceInstaller($executor, sourcePath: '/missing/orbit-reverb')->install(
        Node::factory()->create(),
    ))
        ->toThrow(
            InvalidArgumentException::class,
            'WebSocket runtime source path [/missing/orbit-reverb] does not exist.',
        );
});

function websocket_runtime_source_installer_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: websocket_runtime_source_installer_operation_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
        agentPush: app(\App\Services\NodeCommandTransport\NodeAgentPushDispatcher::class),
        gatewayLocal: app(\App\Services\RemoteShell\GatewayLocalCommandDispatcher::class),
        applicationKey: websocket_runtime_source_installer_operation_secret(),
    );
}

function websocket_runtime_source_installer_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

final class WebSocketRuntimeSourceInstallerUnusedTransport implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('SSH transport should not be called for websocket runtime source installs.');
    }
}
