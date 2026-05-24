<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Vpn\VpnNodeResolver;
use App\Services\Vpn\WgEasyVpnBackend;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('mints client configs with the wireguard server dns address', function (): void {
    config()->set('services.wg_easy.username', 'orbit');
    config()->set('services.wg_easy.password', 'secret-password');

    Http::preventStrayRequests();

    $clientListCalls = 0;

    Http::fake(function (Request $request) use (&$clientListCalls) {
        if ($request->url() === 'http://127.0.0.1:51821/api/session') {
            return Http::response(['status' => 'success'], 200, [
                'Set-Cookie' => 'wg-easy=session-token; Path=/; HttpOnly',
            ]);
        }

        if ($request->method() === 'GET' && $request->url() === 'http://127.0.0.1:51821/api/client') {
            $clientListCalls++;

            return Http::response($clientListCalls === 1 ? [] : [
                [
                    'id' => 'client-7',
                    'name' => 'laptop',
                    'ipv4Address' => '10.6.0.7',
                    'enabled' => true,
                    'latestHandshakeAt' => null,
                ],
            ], 200);
        }

        if ($request->method() === 'POST' && $request->url() === 'http://127.0.0.1:51821/api/client') {
            return Http::response(['id' => 'client-7'], 200);
        }

        if ($request->url() === 'http://127.0.0.1:51821/api/client/client-7/configuration') {
            return Http::response(implode("\n", [
                '[Interface]',
                'PrivateKey = client-private',
                'Address = 10.6.0.7/32',
                'DNS = 10.6.0.2, 1.1.1.1, bear, gateway',
                '',
                '[Peer]',
                'PublicKey = server-public',
                'AllowedIPs = 0.0.0.0/0',
                'Endpoint = vpn.example.com:51820',
                '',
            ]), 200);
        }

        return Http::response("Unexpected request {$request->method()} {$request->url()}", 500);
    });

    $client = WgEasyVpnBackend::fromConfig()->createClient('laptop', includeConfig: true);

    expect($client->config)
        ->toContain('DNS = 10.6.0.1')
        ->not->toContain('10.6.0.2')
        ->not->toContain('1.1.1.1')
        ->not->toContain('bear')
        ->not->toContain('gateway');
});

it('keeps password and session secret updates on deferred sqlite heredocs until the wg-easy state command supports them', function (): void {
    $node = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'vpn',
        'status' => 'active',
    ]);

    $transport = new WgEasyVpnBackendStateTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'action' => 'ensure-writable',
            'writable' => true,
            'ownership_changed' => false,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: '',
        durationMs: 1,
    ));

    Http::fake([
        'http://127.0.0.1:51821/api/session' => Http::response(['status' => 'success'], 200, [
            'Set-Cookie' => 'wg-easy=session-token; Path=/; HttpOnly',
        ]),
        'http://127.0.0.1:51821/api/client' => Http::response([], 200),
    ]);

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if (str_contains($command, 'docker exec -i -w /app/server wg-easy node')) {
            return Process::result('$argon2id$v=19$m=65536,t=3,p=4$hash$hash');
        }

        return Process::result();
    });

    $result = (new WgEasyVpnBackend(
        username: 'orbit',
        password: 'current-secret-password',
        localExecutor: wgEasyVpnBackendExecutor($transport),
        vpnNodeResolver: app(VpnNodeResolver::class),
    ))
        ->changeWebUiPassword('new-secret-password');

    expect($result->passwordChanged)->toBeTrue()
        ->and($result->sessionsInvalidated)->toBeTrue();

    $script = $transport->calls[0]['script'];

    expect($transport->calls)->toHaveCount(1)
        ->and($script)->toContain('internal:wg-easy:state')
        ->and($script)->toContain("--action='ensure-writable'")
        ->and($script)->toContain('--operation-token=')
        ->and($script)->not->toContain('sqlite3')
        ->and($script)->not->toContain('sudo sqlite3');

    Process::assertRan(function ($process): bool {
        $command = (string) $process->command;

        return str_contains($command, 'sqlite3')
            && str_contains($command, 'wg-easy.db')
            && ! str_contains($command, 'UPDATE users_table')
            && is_string($process->input)
            && str_contains($process->input, 'UPDATE users_table SET password');
    });

    Process::assertRan(function ($process): bool {
        $command = (string) $process->command;

        return str_contains($command, 'sqlite3')
            && str_contains($command, 'wg-easy.db')
            && ! str_contains($command, 'UPDATE general_table')
            && is_string($process->input)
            && str_contains($process->input, 'UPDATE general_table SET session_password');
    });
});

function wgEasyVpnBackendExecutor(WgEasyVpnBackendStateTransport $transport): RemoteLocalExecutor
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
    );
}

final class WgEasyVpnBackendStateTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param  RemoteShellResult|Closure(Node, string, array<string, mixed>): RemoteShellResult  $result
     */
    public function __construct(
        private readonly RemoteShellResult|Closure $result,
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

        if ($this->result instanceof Closure) {
            return ($this->result)($node, $script, $options);
        }

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
