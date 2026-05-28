<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Vpn\ArrayVpnBackend;
use App\Services\Vpn\VpnBackend;
use App\Services\Vpn\WgEasyVpnBackend;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

uses(RefreshDatabase::class);

it('rotates the vpn web ui password without printing the secret', function (): void {
    vpnLocalNode('gateway');
    $backend = new ArrayVpnBackend;
    app()->instance(VpnBackend::class, $backend);

    $exitCode = Artisan::call('vpn-web-ui:change-password', [
        'password' => 'new-secret-password',
        '--force' => true,
        '--json' => true,
    ]);
    $output = Artisan::output();
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['vpn'])->toBe([
            'password_changed' => true,
            'sessions_invalidated' => true,
        ])
        ->and($output)->not->toContain('new-secret-password')
        ->and($backend->changedPassword)->toBe('new-secret-password');
});

it('requires password and force in json mode', function (): void {
    vpnLocalNode('gateway');
    app()->instance(VpnBackend::class, new ArrayVpnBackend);

    $missingPassword = Artisan::call('vpn-web-ui:change-password', ['--force' => true, '--json' => true]);
    $missingPasswordPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    $missingForce = Artisan::call('vpn-web-ui:change-password', [
        'password' => 'new-secret-password',
        '--json' => true,
    ]);
    $missingForcePayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($missingPassword)->toBe(1)
        ->and($missingPasswordPayload['error']['meta']['field'])->toBe('password')
        ->and($missingForce)->toBe(1)
        ->and($missingForcePayload['error']['meta'])->toBe([
            'field' => 'force',
            'reason' => 'destructive_consent_required',
        ]);
});

it('rotates the wg-easy backend password through argon2 and local executor without argv secrets', function (): void {
    $node = vpnLocalNode('gateway');

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'vpn',
        'status' => 'active',
    ]);

    config()->set('orbit.operation_token_secret', 'gateway-secret');
    config()->set('orbit.operation_token_ttl_seconds', 120);
    config()->set('services.wg_easy.username', 'orbit');
    config()->set('services.wg_easy.password', 'current-secret-password');

    $hash = '$argon2id$v=19$m=65536,t=3,p=4$hash$hash';
    $transport = new VpnWebUiChangePasswordLocalExecutorTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success(['updated' => true]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: '',
        durationMs: 1,
    ));

    app()->instance(RemoteLocalExecutor::class, vpnWebUiChangePasswordLocalExecutor($transport));
    app()->forgetInstance(WgEasyVpnBackend::class);

    Http::fake([
        'http://127.0.0.1:51821/api/session' => Http::response(['status' => 'success'], 200, [
            'Set-Cookie' => 'wg-easy=session-token; Path=/; HttpOnly',
        ]),
        'http://127.0.0.1:51821/api/client' => Http::response([], 200),
    ]);

    Process::fake(function ($process) use ($hash) {
        $command = (string) $process->command;

        if (str_contains($command, 'docker exec -i -w /app/server wg-easy node')) {
            return Process::result($hash);
        }

        return Process::result();
    });

    $newPassword = 'new-secret-password';
    $exitCode = Artisan::call('vpn-web-ui:change-password', [
        'password' => $newPassword,
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['vpn']['password_changed'])->toBeTrue();

    Process::assertRan(function ($process) use ($newPassword): bool {
        $command = (string) $process->command;

        return str_contains($command, 'docker exec -i -w /app/server wg-easy node')
            && ! str_contains($command, $newPassword)
            && $process->input === $newPassword;
    });

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'sqlite3'));

    $scripts = array_column($transport->calls, 'script');

    expect($scripts)->toHaveCount(3)
        ->and($scripts[0])->toContain("--action='ensure-writable'")
        ->and($scripts[1])->toContain("--action='update-user-password'")
        ->and($scripts[1])->toContain("--password-hash='{$hash}'")
        ->and($scripts[2])->toContain("--action='update-session-password'")
        ->and($scripts[2])->toContain("--password-hash='{$hash}'");

    foreach ($scripts as $script) {
        expect($script)->toContain('internal:wg-easy:state')
            ->and($script)->toContain('--operation-token=')
            ->and($script)->not->toContain('sqlite3')
            ->and($script)->not->toContain('sudo sqlite3')
            ->and($script)->not->toContain($newPassword);
    }
});

function vpnWebUiChangePasswordLocalExecutor(VpnWebUiChangePasswordLocalExecutorTransport $transport): RemoteLocalExecutor
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
    );
}

final class VpnWebUiChangePasswordLocalExecutorTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('The recording transport does not start processes.');
    }
}
