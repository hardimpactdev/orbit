<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\SetNodePlatformRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;

describe('node:platform', function (): void {
    beforeEach(function (): void {
        MockClient::destroyGlobal();
        $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-platform-'.Str::uuid();
        config()->set('orbit.home', $this->orbitHome);
        app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/home/orbit/.orbit/ca/root.pem'));
        $this->requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $this->payload = [
            'id' => 2, 'name' => 'ipad', 'status' => 'active', 'platform' => 'ipados', 'architecture' => 'arm64',
            'public_ssh_host' => '10.44.0.12', 'public_ssh_port' => 22, 'user' => 'ipad',
            'wireguard_ip' => '10.44.0.12', 'roles' => [],
        ];
    });

    afterEach(function (): void {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($this->orbitHome);
    });

    it('resolves an exact name and sends only the platform', function (): void {
        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make(['data' => [$this->payload], 'meta' => ['request_id' => $this->requestId]]),
            SetNodePlatformRequest::class => MockResponse::make(['data' => $this->payload, 'meta' => ['request_id' => $this->requestId]]),
        ]);

        $this->artisan('node:platform', ['node' => 'ipad', 'platform' => 'ipados'])
            ->expectsOutput('Platform set to ipados for Node #2 [ipad].')
            ->expectsOutput('Request ID: '.$this->requestId)->assertExitCode(0);

        $request = $mock->getLastRequest();
        expect($request)->toBeInstanceOf(SetNodePlatformRequest::class)
            ->and($request?->getMethod())->toBe(Method::PATCH)
            ->and($request?->resolveEndpoint())->toBe('/api/v1/nodes/2/platform')
            ->and($request?->body()->all())->toBe(['platform' => 'ipados']);
    });

    it('replays the recorded success and refusal in human and JSON output', function (string $fixture, int $status): void {
        $arguments = ['node' => '2', 'platform' => 'ipados'];
        run_contract($fixture, 'node:platform', $arguments, $fixture.'.human.txt', $status);
        run_contract($fixture, 'node:platform', [...$arguments, '--json' => true], $fixture.'.json', $status);
    })->with([
        ['nodes/node-platform/updated', 0],
        ['nodes/node-platform/managed-refused', 1],
    ]);

    it('refuses missing arguments before connector IO', function (array $arguments, string $code): void {
        $mock = MockClient::global();
        $this->artisan('node:platform', [...$arguments, '--json' => true])
            ->expectsOutputToContain('"code":"'.$code.'"')->assertExitCode(1);
        expect($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'missing node' => [[], 'node.reference_required'],
        'missing platform' => [['node' => '2'], 'node.platform_required'],
    ]);

    it('prompts for the Node and platform, and cancels without mutation', function (string $outcome): void {
        $directory = $this->orbitHome.'/pty';
        new Filesystem()->ensureDirectoryExists($directory);
        $keys = match ($outcome) {
            'success' => ["\r", "\e[B", "\e[B", "\r"],
            'cancel-node' => ["\x03"],
            'cancel-platform' => ["\r", "\x03"],
        };
        $configuration = [
            'home' => $directory.'/home', 'trace' => $directory.'/requests.jsonl', 'arguments' => ['node:platform'],
            'prompt' => 'ipad', 'keys' => array_map(base64_encode(...), $keys), 'columns' => 100,
            'replies' => [
                ['class' => ListNodesRequest::class, 'body' => ['data' => [$this->payload], 'meta' => ['request_id' => $this->requestId]]],
                ['class' => SetNodePlatformRequest::class, 'body' => ['data' => $this->payload, 'meta' => ['request_id' => $this->requestId]]],
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
            expect($raw)->toContain('Platform', 'Platform set to ipados for Node #2 [ipad].')
                ->and($requests)->toContain('SetNodePlatformRequest', '"platform":"ipados"');
        } else {
            expect($requests)->not->toContain('SetNodePlatformRequest');
        }
    })->with(['success', 'cancel-node', 'cancel-platform']);
});
