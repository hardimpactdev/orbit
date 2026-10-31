<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;

it('follows an unmanaged peer-key Operation and renders its terminal result', function (bool $json, int $node): void {
    $home = sys_get_temp_dir().'/orbit-converge-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $operation = ['id' => 7, 'kind' => 'node.wireguard_key', 'target_type' => 'node', 'target_id' => $node,
        'status' => 'succeeded', 'sequence' => 3, 'input' => [], 'steps' => [], 'result' => [],
        'finished_at' => '2026-10-28T12:00:00Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        SetNodeWireGuardKeyRequest::class => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);

    try {
        $this->artisan('node:wireguard-key', ['node' => (string) $node,
            'public-key' => base64_encode(str_repeat('n', 32)), '--json' => $json])
            ->expectsOutputToContain($json ? 'node.wireguard_key' : 'Node WireGuard key')->assertExitCode(0);
        $mock->assertSent(fn ($pending): bool => $pending instanceof SetNodeWireGuardKeyRequest
            && $pending->body()->all() === ['wireguard_public_key' => base64_encode(str_repeat('n', 32))]);
        if ($json) {
            $mock->assertSent(ShowOperationRequest::class);
        }
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
})->with([true, false])->with([1, 17]);

describe('key argument and refusal boundaries', function (): void {
    beforeEach(function (): void {
        $this->requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $this->key = base64_encode(str_repeat('n', 32));
        $this->payload = ['id' => 2, 'name' => 'laptop', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'aarch64',
            'public_ssh_host' => '192.0.2.2', 'public_ssh_port' => 22, 'user' => 'orbit',
            'wireguard_ip' => '10.44.0.2', 'wireguard_public_key' => $this->key, 'roles' => []];
        $this->terminal = ['id' => 7, 'kind' => 'node.wireguard_key', 'target_type' => 'node', 'target_id' => 2,
            'status' => 'succeeded', 'sequence' => 3, 'input' => [], 'steps' => [], 'result' => [],
            'finished_at' => '2026-10-28T12:00:00Z'];
        $this->orbitHome = sys_get_temp_dir().'/orbit-key-boundary-'.Str::uuid();
        config(['orbit.home' => $this->orbitHome]);
        app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    });
    afterEach(function (): void {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($this->orbitHome);
    });
    it('refuses missing or invalid arguments before connector IO', function (array $arguments, string $code): void {
        $mock = MockClient::global();
        $this->artisan('node:wireguard-key', [...$arguments, '--json' => true])
            ->expectsOutputToContain('"code":"'.$code.'"')->assertExitCode(1);
        expect($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'missing node' => [[], 'node.reference_required'],
        'missing key' => [['node' => '2'], 'node.wireguard_key_required'],
        'invalid key' => [['node' => '2', 'public-key' => 'private-key-input'], 'validation.failed'],
        'whitespace' => [['node' => '2', 'public-key' => ' '.base64_encode(str_repeat('n', 32))], 'validation.failed'],
        'noncanonical' => [['node' => '2', 'public-key' => str_repeat('A', 42).'B='], 'validation.failed'],
    ]);

    it('preserves refusal and projection error codes and request IDs without success', function (string $code, int $status): void {
        MockClient::global([
            SetNodeWireGuardKeyRequest::class => MockResponse::make([
                'error' => ['code' => $code, 'message' => 'The operation could not complete.'],
            ], $status, ['X-Orbit-Request-Id' => $this->requestId]),
        ]);
        $expected = json_encode(['error' => ['code' => $code, 'message' => 'The operation could not complete.', 'request_id' => $this->requestId]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->artisan('node:wireguard-key', ['node' => '2', 'public-key' => $this->key, '--json' => true])
            ->expectsOutput($expected)->assertExitCode(1);
    })->with([
        ['node.wireguard_key_managed', 409], ['node.wireguard_key_gateway', 409], ['node.wireguard_key_conflict', 409],
        ['operation.native_failed', 422],
    ]);
    it('prompts for the Node and key, revalidates invalid input, and cancels without mutation', function (string $outcome): void {
        $directory = $this->orbitHome.'/pty';
        new Filesystem()->ensureDirectoryExists($directory);
        $keys = $outcome === 'cancel-node' ? ["\x03"] : ["\r"];

        if ($outcome === 'cancel-key') {
            $keys[] = "\x03";
        } elseif ($outcome === 'success') {
            $keys = [...$keys, 'b', 'a', 'd', "\r", "\x7f", "\x7f", "\x7f", ...str_split($this->key), "\r"];
        }

        $configuration = [
            'home' => $directory.'/home', 'trace' => $directory.'/requests.jsonl', 'arguments' => ['node:wireguard-key'],
            'prompt' => 'laptop', 'keys' => array_map(base64_encode(...), $keys), 'columns' => 100,
            'replies' => [
                ['class' => ListNodesRequest::class, 'body' => ['data' => [$this->payload], 'meta' => ['request_id' => $this->requestId]]],
                ['class' => SetNodeWireGuardKeyRequest::class, 'body' => ['data' => $this->terminal, 'meta' => ['request_id' => $this->requestId]]],
                ['class' => ShowRealtimeRequest::class, 'body' => ['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 'status' => 404],
            ],
        ];
        file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
        $fixtures = base_path('tests/Fixtures/Console/');
        $process = new Process(['python3', $fixtures.'command-consent-pty.py', PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json'], env: ['PAO_DISABLE' => '1'], timeout: 15);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $raw = base64_decode($result['raw'], strict: true);
        expect($result['status'])->toBe($outcome === 'success' ? 0 : 1, $raw)
            ->and($result['restored'])->toBeTrue()->and($result['keys_remaining'])->toBe(0);
        $requests = (string) file_get_contents($directory.'/requests.jsonl');

        if ($outcome === 'success') {
            expect($raw)->toContain('WireGuard public key', 'canonical base64', 'Node WireGuard key')
                ->and($requests)->toContain('SetNodeWireGuardKeyRequest', $this->key);
        } else {
            expect($requests)->not->toContain('SetNodeWireGuardKeyRequest');
        }
    })->with(['success', 'cancel-node', 'cancel-key']);

    it('resolves an exact Node name before admitting the key Operation', function (): void {
        $envelope = ['data' => $this->terminal, 'meta' => ['request_id' => $this->requestId]];
        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make(['data' => [$this->payload], 'meta' => ['request_id' => $this->requestId]]),
            SetNodeWireGuardKeyRequest::class => MockResponse::make($envelope, 202),
            ShowRealtimeRequest::class => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
        ]);
        $this->artisan('node:wireguard-key', ['node' => 'laptop', 'public-key' => $this->key])
            ->expectsOutputToContain('Node WireGuard key')->assertSuccessful();
        $mock->assertSent(fn ($request): bool => $request instanceof SetNodeWireGuardKeyRequest && $request->resolveEndpoint() === '/api/v1/nodes/2/wireguard-key');
    });
});

it('follows the recorded Gateway admission contract', function (): void {
    $home = sys_get_temp_dir().'/orbit-key-contract-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $fixture = gateway_fixture('nodes/node-wireguard-key/updated');
    $terminal = json_decode($fixture['body'], true, flags: JSON_THROW_ON_ERROR);
    $terminal['data']['status'] = 'succeeded';
    $terminal['data']['sequence'] = 3;
    $terminal['data']['finished_at'] = '2026-10-31T12:01:00Z';
    MockClient::global([
        ...gateway_fixture_mock('nodes/node-wireguard-key/updated'),
        ShowOperationRequest::class => MockResponse::make($terminal),
        ShowRealtimeRequest::class => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);
    try {
        $this->artisan('node:wireguard-key', ['node' => '3', 'public-key' => base64_encode(str_repeat('b', 32)), '--json' => true])
            ->expectsOutputToContain('node.wireguard_key')->assertExitCode(0);
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
});
