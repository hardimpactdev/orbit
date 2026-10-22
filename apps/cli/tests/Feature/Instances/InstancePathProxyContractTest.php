<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\SetInstancePathProxyRequest;
use Orbit\Sdk\Requests\Instances\ShowInstancePathProxyRequest;
use Orbit\Sdk\Requests\Instances\UnsetInstancePathProxyRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-path-proxy-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.orbit', '/fixture/ca.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('sends the explicit Process port and prefix and renders the endpoint', function (): void {
    $mock = MockClient::global([SetInstancePathProxyRequest::class => MockResponse::make([
        'data' => ['instance_id' => 71, 'route_id' => 100, 'process_id' => 123, 'port' => 8080,
            'prefix' => '/powersync', 'endpoint' => 'https://hauzer.app/powersync', 'enabled' => true],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ])]);
    $tester = new CommandTester(app(Kernel::class)->all()['instance:path-proxy:set']);
    expect($tester->execute(['--instance' => 'hauzer.app', '--process' => '123', '--port' => '8080', '--json' => true], ['interactive' => false]))->toBe(0);
    expect(json_decode($tester->getDisplay(true), true, flags: JSON_THROW_ON_ERROR)['endpoint'])->toBe('https://hauzer.app/powersync');
    expect($mock->getLastRequest()?->body()->all())->toBe(['process_id' => 123, 'port' => 8080, 'prefix' => '/powersync']);
});

it('refuses missing or invalid input before contacting the Gateway', function (array $arguments): void {
    $mock = MockClient::global([]);
    $tester = new CommandTester(app(Kernel::class)->all()['instance:path-proxy:set']);
    expect($tester->execute([...$arguments, '--json' => true], ['interactive' => false]))->toBe(1);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    [[]], [['--instance' => 'hauzer.app']], [['--instance' => '71', '--process' => '123']],
    [['--instance' => '71', '--process' => 'invalid', '--port' => '8080']],
    [['--instance' => '71', '--process' => '123', '--port' => '65536']],
]);

it('requires removal consent before sending and removes with force', function (): void {
    $mock = MockClient::global([UnsetInstancePathProxyRequest::class => MockResponse::make([
        'data' => ['instance_id' => 71], 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ])]);
    $tester = new CommandTester(app(Kernel::class)->all()['instance:path-proxy:unset']);
    expect($tester->execute(['--instance' => '71', '--json' => true], ['interactive' => false]))->toBe(1);
    expect($tester->getDisplay(true))->toContain('path_proxy.confirmation_required');
    expect($mock->getLastPendingRequest())->toBeNull();
    expect($tester->execute(['--instance' => '71', '--force' => true, '--json' => true], ['interactive' => false]))->toBe(0);
    expect($mock->getLastRequest())->toBeInstanceOf(UnsetInstancePathProxyRequest::class);
});

it('preserves a Gateway error and request ID as clean JSON', function (): void {
    MockClient::global([ShowInstancePathProxyRequest::class => MockResponse::make([
        'error' => ['code' => 'path_proxy.missing', 'message' => 'This Instance has no path proxy.'],
    ], 404, ['X-Orbit-Request-Id' => '11111111-1111-4111-8111-111111111111'])]);
    $tester = new CommandTester(app(Kernel::class)->all()['instance:path-proxy:show']);
    expect($tester->execute(['--instance' => '71', '--json' => true], ['interactive' => false]))->toBe(1);
    $error = json_decode($tester->getDisplay(true), true, flags: JSON_THROW_ON_ERROR)['error'];
    expect($error['code'])->toBe('path_proxy.missing')->and($error['request_id'])->toBe('11111111-1111-4111-8111-111111111111');
});

it('replays recorded Gateway outcomes in human and JSON modes', function (bool $json): void {
    $suffix = $json ? '.json' : '.human.txt';
    foreach ([
        ['path-proxies/instance-path-proxy-set/default', 'set', ['--process' => '1', '--port' => '8080'], 0],
        ['path-proxies/instance-path-proxy-show/default', 'show', [], 0],
        ['path-proxies/instance-path-proxy-unset/default', 'unset', ['--force' => true], 0],
        ['path-proxies/instance-path-proxy-show/missing', 'show', [], 1],
    ] as [$name, $operation, $options, $status]) {
        run_contract($name, 'instance:path-proxy:'.$operation, ['--instance' => '1', '--json' => $json, ...$options], $name.$suffix, $status);
    }
})->with(['human' => false, 'json' => true]);
