<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

describe('node:wireguard-key', function (): void {
    beforeEach(function (): void {
        MockClient::destroyGlobal();
        $this->orbitHome = sys_get_temp_dir().'/orbit-cli-wireguard-key-'.Str::uuid();
        config()->set('orbit.home', $this->orbitHome);
        app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/home/orbit/.orbit/ca/root.pem'));
        $this->key = base64_encode(str_repeat('n', 32));
        $this->requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $this->payload = [
            'id' => 2, 'name' => 'laptop', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'aarch64',
            'public_ssh_host' => '192.0.2.2', 'public_ssh_port' => 22, 'user' => 'orbit',
            'wireguard_ip' => '10.44.0.2', 'wireguard_public_key' => $this->key, 'roles' => [],
        ];
    });

    afterEach(function (): void {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($this->orbitHome);
    });

    it('transports the exact typed PATCH and emits only the standard Node JSON', function (): void {
        $mock = MockClient::global([
            SetNodeWireGuardKeyRequest::class => MockResponse::make(['data' => $this->payload, 'meta' => ['request_id' => $this->requestId]]),
        ]);
        $expected = json_encode(NodeResponse::fromGatewayData($this->payload, $this->requestId)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->artisan('node:wireguard-key', ['node' => '2', 'public-key' => $this->key, '--json' => true])
            ->expectsOutput($expected)->assertExitCode(0);
        $request = $mock->getLastRequest();
        expect($request)->toBeInstanceOf(SetNodeWireGuardKeyRequest::class)
            ->and($request?->getMethod())->toBe(Method::PATCH)
            ->and($request?->resolveEndpoint())->toBe('/api/v1/nodes/2/wireguard-key')
            ->and($request?->body()->all())->toBe(['wireguard_public_key' => $this->key]);
    });

    it('resolves an exact name and prints the documented human success', function (): void {
        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make(['data' => [$this->payload], 'meta' => ['request_id' => $this->requestId]]),
            SetNodeWireGuardKeyRequest::class => MockResponse::make(['data' => $this->payload, 'meta' => ['request_id' => $this->requestId]]),
        ]);
        $this->artisan('node:wireguard-key', ['node' => 'laptop', 'public-key' => $this->key])
            ->expectsOutput('WireGuard public key set for Node #2 [laptop].')
            ->expectsOutput('Request ID: '.$this->requestId)->assertExitCode(0);
        expect($mock->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/nodes/2/wireguard-key');
    });

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
                ['class' => SetNodeWireGuardKeyRequest::class, 'body' => ['data' => $this->payload, 'meta' => ['request_id' => $this->requestId]]],
            ],
        ];
        file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
        $fixtures = base_path('tests/Fixtures/Console/');
        $process = new Process(['python3', $fixtures.'command-consent-pty.py', PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json'], env: ['PAO_DISABLE' => '1'], timeout: 15);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $raw = base64_decode($result['raw'], strict: true);
        expect($result['status'])->toBe($outcome === 'success' ? 0 : 1)
            ->and($result['restored'])->toBeTrue()->and($result['keys_remaining'])->toBe(0);
        $requests = (string) file_get_contents($directory.'/requests.jsonl');

        if ($outcome === 'success') {
            expect($raw)->toContain('WireGuard public key', 'canonical base64', 'WireGuard public key set for Node #2 [laptop].')
                ->and($requests)->toContain('SetNodeWireGuardKeyRequest', $this->key);
        } else {
            expect($requests)->not->toContain('SetNodeWireGuardKeyRequest');
        }
    })->with(['success', 'cancel-node', 'cancel-key']);

    it('replays the recorded success and safe failures in human and JSON output', function (string $fixture, int $status): void {
        $arguments = ['node' => '2', 'public-key' => $this->key];
        run_contract($fixture, 'node:wireguard-key', $arguments, $fixture.'.human.txt', $status);
        run_contract($fixture, 'node:wireguard-key', [...$arguments, '--json' => true], $fixture.'.json', $status);
    })->with([
        ['nodes/node-wireguard-key/updated', 0],
        ['nodes/node-wireguard-key/gateway-refused', 1],
        ['nodes/node-wireguard-key/projection-failed', 1],
    ]);

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
        ['vpn.server_config_install_failed', 422],
    ]);
});
