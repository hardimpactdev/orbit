<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\ScanToolInventoryRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tool-scan-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

it('sends only the inventory read and renders formula, dependency, unsupported and not observed rows', function (): void {
    $mock = MockClient::global(gateway_fixture_mock('tools/tool-scan/discovered'));

    expect(Artisan::call('tool:scan', ['--node' => '2']))->toBe(0);
    $output = Artisan::output();

    expect($output)
        ->toContain('ripgrep')
        ->toContain('formula')
        ->toContain('pcre2')
        ->toContain('dependency')
        ->toContain('wireguard-tools')
        ->toContain('protected')
        ->toContain('unsupported_source')
        ->toContain('@openai/codex')
        ->toContain('pnpm')
        ->toContain('Node #2 observed at 2026-04-26T11:59:00+00:00.')
        ->toContain('apt is not observed yet, so its package list is not an inventory.');

    expect($mock->getLastRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tool-inventory')
        ->and($mock->getLastRequest()?->query()->all())
        ->toBe(['node_id' => 2])
        ->and($mock->getLastRequest())
        ->toBeInstanceOf(ScanToolInventoryRequest::class)
        ->and($mock->getLastPendingRequest()?->body())->toBeNull();
});

it('rejects an invalid node id before any request', function (): void {
    $mock = MockClient::global();

    expect(Artisan::call('tool:scan', ['--node' => '0', '--json' => true]))
        ->toBe(1)
        ->and(trim(Artisan::output()))
        ->toBe(json_encode(['error' => [
            'code' => 'tool.node_id_invalid',
            'message' => 'Node ID must be a positive integer.',
            'request_id' => null,
        ]], JSON_THROW_ON_ERROR))
        ->and($mock->getLastPendingRequest())
        ->toBeNull();
});
