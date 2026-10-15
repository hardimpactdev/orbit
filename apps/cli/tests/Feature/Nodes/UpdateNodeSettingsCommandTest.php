<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\UpdateNodeSettingsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function node_settings_profile(): void
{
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
}

it('sends repeatable known settings including a later colon and an empty unset', function (): void {
    node_settings_profile();
    $mockClient = MockClient::global([
        '*/api/v1/nodes/2/settings' => MockResponse::make([
            'data' => [
                'id' => 2,
                'name' => 'app-dev',
                'status' => 'active',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'roles' => ['app-dev'],
                'settings' => [
                    'apps' => ['path' => '/srv/orbit:data/apps'],
                ],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
        '*/api/v1/nodes' => MockResponse::make([
            'data' => [[
                'id' => 2,
                'name' => 'app-dev',
                'status' => 'active',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'roles' => ['app-dev'],
            ]],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);

    $this
        ->artisan('node:settings', [
            'node' => 'app-dev',
            '--setting' => [
                'apps.path:/srv/orbit:data/apps',
            ],
            '--json' => true,
        ])
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(UpdateNodeSettingsRequest::class)
        ->and($request?->body()->all())
        ->toBe([
            'apps' => ['path' => '/srv/orbit:data/apps'],
        ]);
});

it('transports typed host configuration and clearing without changing apps', function (?array $host): void {
    node_settings_profile();
    $mockClient = MockClient::global([
        '*/api/v1/nodes/9/settings' => MockResponse::make([
            'data' => ['id' => 9, 'name' => 'beast', 'status' => 'active', 'public_ssh_host' => '192.0.2.9', 'settings' => $host === null ? null : ['task_vms' => $host]],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $this->artisan('node:settings', ['node' => '9', '--setting' => ['task_vms:'.json_encode($host)], '--json' => true])->assertExitCode(0);
    expect($mockClient->getLastRequest()?->body()->all())->toBe(['task_vms' => $host]);
})->with([
    'configured host' => [['cidr' => '10.251.77.0/24', 'max_vms' => 4, 'image_alias' => 'custom-base', 'warm_vms' => 0]],
    'host with operator-owned resources' => [['cidr' => '10.251.77.0/24', 'max_vms' => 4, 'operator_owned' => ['orbit-ci/*', 'orbit-tasks/retained']]],
    'cleared host' => [null],
]);

it('rejects duplicate, unknown, and malformed settings before making a request', function (
    array $settings,
    string $message,
): void {
    node_settings_profile();
    $mockClient = MockClient::global();

    $this
        ->artisan('node:settings', [
            'node' => '2',
            '--setting' => $settings,
        ])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'duplicate key' => [['apps.path:/srv/a', 'apps.path:/srv/b'], 'supplied more than once'],
    'unknown key' => [['packages.path:/srv/a'], 'Unknown setting'],
    'removed task worker key' => [['tasks.worker:none'], 'Unknown setting'],
    'missing colon' => [['apps.path'], 'setting-path'],
    'empty key' => [[':/srv/a'], 'setting-path'],
    'duplicate host' => [['task_vms:null', 'task_vms:null'], 'supplied more than once'],
    'invalid host JSON' => [['task_vms:{'], 'typed JSON host object'],
    'host is a list' => [['task_vms:[]'], 'typed JSON host object'],
    'host unknown field' => [['task_vms:{"cidr":"10.251.77.0/24","max_vms":4,"unknown":true}'], 'typed JSON host object'],
    'string capacity' => [['task_vms:{"cidr":"10.251.77.0/24","max_vms":"4"}'], 'typed JSON host object'],
    'non-string operator-owned resource' => [['task_vms:{"cidr":"10.251.77.0/24","max_vms":4,"operator_owned":["orbit-ci/*",7]}'], 'typed JSON host object'],
    'operator-owned object instead of list' => [['task_vms:{"cidr":"10.251.77.0/24","max_vms":4,"operator_owned":{"resource":"orbit-ci/*"}}'], 'typed JSON host object'],
]);
