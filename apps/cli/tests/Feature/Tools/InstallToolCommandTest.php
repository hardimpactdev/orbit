<?php

declare(strict_types=1);

use App\Commands\Tools\InstallToolCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Responses\Tools\ToolManagerResponse;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-install-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/tmp/ca.pem'));
});
afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function install_payload(string $outcome = 'applied'): array
{
    return [
        'id' => 41,
        'node_id' => 12,
        'manager' => 'vp',
        'package' => '@openai/codex',
        'version_constraint' => null,
        'status' => 'installed',
        'installed_version' => '0.150.0',
        'failed_operation' => null,
        'error_code' => null,
        'outcome' => $outcome,
    ];
}

it('sends supplied manager and constraint without manager lookup', function (): void {
    $id = '33333333-3333-4333-8333-333333333333';
    $mock = MockClient::global([
        InstallToolRequest::class => MockResponse::make(['data' => install_payload(), 'meta' => ['request_id' => $id]]),
    ]);
    $this->artisan('tool:install', [
        'package' => '@openai/codex',
        '--node' => 12,
        '--manager' => 'unlisted',
        '--constraint' => '^1.2',
        '--json' => true,
    ])->assertSuccessful();
    $request = $mock->getLastPendingRequest();
    expect($request?->getMethod()->value)
        ->toBe('POST')
        ->and($request?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools')
        ->and($request?->body()->all())
        ->toBe([
            'node_id' => 12,
            'manager' => 'unlisted',
            'package' => '@openai/codex',
            'version_constraint' => '^1.2',
        ]);
});

it('rejects missing manager and package in JSON mode before HTTP', function (): void {
    $mock = MockClient::global();
    $this->artisan('tool:install', ['--node' => 12, 'package' => 'pkg', '--json' => true])->assertExitCode(1);
    $this->artisan('tool:install', ['--node' => 12, '--manager' => 'vp', '--json' => true])->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
});

it('rejects empty, control, and oversized packages before HTTP', function (string $package): void {
    $mock = MockClient::global();
    $this->artisan('tool:install', ['package' => $package, '--node' => 12, '--manager' => 'vp'])->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'empty' => '',
    'control' => "bad\tname",
    'oversized' => str_repeat(string: 'x', times: 256),
]);

it('rejects missing inputs invalid packages and nodes before HTTP', function (): void {
    $mock = MockClient::global();
    $this->artisan('tool:install', ['--node' => 12, '--json' => true])->assertExitCode(1);
    $this->artisan('tool:install', ['--node' => 12, '--manager' => 'vp', 'package' => "bad\nname"])->assertExitCode(1);
    $this->artisan('tool:install', ['--node' => 0, '--manager' => 'vp', 'package' => 'pkg'])->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
});

it('uses noninteractive manager and package rules without prompting', function (): void {
    $mock = MockClient::global();

    $missingManager = app(InstallToolCommand::class);
    $missingManager->setLaravel(app());
    $managerTester = new CommandTester($missingManager);
    $managerTester->execute(['--node' => '12'], ['interactive' => false]);

    $missingPackage = app(InstallToolCommand::class);
    $missingPackage->setLaravel(app());
    $packageTester = new CommandTester($missingPackage);
    $packageTester->execute(['--node' => '12', '--manager' => 'vp'], ['interactive' => false]);

    expect($managerTester->getStatusCode())
        ->toBe(1)
        ->and($managerTester->getDisplay())
        ->toContain('Tool manager is required.')
        ->and($packageTester->getStatusCode())
        ->toBe(1)
        ->and($packageTester->getDisplay())
        ->toContain('Package is required.');
    $mock->assertNothingSent();
});

it('excludes a failed manager from the install data list', function (): void {
    $managers = new ToolManagersResponse([
        tool_manager_response('apt', 'active'),
        tool_manager_response('composer', 'uninstalled'),
        tool_manager_response('brew', 'failed'),
    ], '11111111-1111-4111-8111-111111111111');

    $method = new ReflectionMethod(InstallToolCommand::class, 'eligibleManagerRows');
    $rows = $method->invoke(null, $managers);

    expect($rows)->toHaveKeys(['apt', 'composer'])
        ->and($rows)->not->toHaveKey('brew');
});

it('reports no supported manager when every manager is ineligible', function (): void {
    $managers = new ToolManagersResponse([
        tool_manager_response('brew', 'failed'),
    ], '11111111-1111-4111-8111-111111111111');

    $method = new ReflectionMethod(InstallToolCommand::class, 'eligibleManagerRows');
    $rows = $method->invoke(null, $managers);

    expect($rows)->toBe([]);
});

function tool_manager_response(string $name, string $status): ToolManagerResponse
{
    return new ToolManagerResponse(
        id: 1,
        nodeId: 12,
        name: $name,
        status: $status,
        installedVersion: null,
        failedStep: $status === 'failed' ? 'install' : null,
        errorCode: null,
        requestId: '11111111-1111-4111-8111-111111111111',
    );
}
