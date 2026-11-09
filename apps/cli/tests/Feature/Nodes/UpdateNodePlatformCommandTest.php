<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\UpdateNodeRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;

describe('node:update --platform', function (): void {
    beforeEach(function (): void {
        $this->originalColumns = getenv('COLUMNS');
        putenv('COLUMNS=80');
        MockClient::destroyGlobal();
        $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-update-'.Str::uuid();
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
        putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($this->orbitHome);
    });

    it('uses a numeric ID and sends only the platform', function (): void {
        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make(['data' => [$this->payload], 'meta' => ['request_id' => $this->requestId]]),
            UpdateNodeRequest::class => MockResponse::make(['data' => $this->payload, 'meta' => ['request_id' => $this->requestId]]),
        ]);

        $this->artisan('node:update', ['node' => '2', '--platform' => 'ipados'])
            ->expectsOutput('Node #2 [ipad] updated.')
            ->expectsOutput('Request ID: '.$this->requestId)->assertExitCode(0);

        $request = $mock->getLastRequest();
        expect($request)->toBeInstanceOf(UpdateNodeRequest::class)
            ->and($request?->getMethod())->toBe(Method::PATCH)
            ->and($request?->resolveEndpoint())->toBe('/api/v1/nodes/2')
            ->and($request?->body()->all())->toBe(['platform' => 'ipados']);
    });

    it('replays the recorded success and refusal in human and JSON output', function (string $fixture, int $status): void {
        $arguments = ['node' => '2', '--platform' => 'ipados'];
        run_contract($fixture, 'node:update', $arguments, $fixture.'.human.txt', $status);
        run_contract($fixture, 'node:update', [...$arguments, '--json' => true], $fixture.'.json', $status);
    })->with([
        ['nodes/node-update/platform-updated', 0],
        ['nodes/node-update/platform-managed-refused', 1],
    ]);

    it('prompts for the Node, and cancels without mutation', function (string $outcome): void {
        $directory = $this->orbitHome.'/pty';
        new Filesystem()->ensureDirectoryExists($directory);
        $keys = match ($outcome) {
            'success' => ["\r"],
            'cancel-node' => ["\x03"],
        };
        $configuration = [
            'home' => $directory.'/home', 'trace' => $directory.'/requests.jsonl', 'arguments' => ['node:update', '--platform=ipados'],
            'prompt' => 'ipad', 'keys' => array_map(base64_encode(...), $keys), 'columns' => 100,
            'replies' => [
                ['class' => ListNodesRequest::class, 'body' => ['data' => [$this->payload], 'meta' => ['request_id' => $this->requestId]]],
                ['class' => UpdateNodeRequest::class, 'body' => ['data' => $this->payload, 'meta' => ['request_id' => $this->requestId]]],
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
            expect($raw)->toContain('Node #2 [ipad] updated.')
                ->and($requests)->toContain('UpdateNodeRequest', '"platform":"ipados"');
        } else {
            expect($requests)->not->toContain('UpdateNodeRequest');
        }
    })->with(['success', 'cancel-node']);
});
