<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\UpdateNodeRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
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

function node_update_profile(): void
{
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
}

it('sends the apps folder, including a colon in the path', function (): void {
    node_update_profile();
    $mockClient = MockClient::global([
        '*/api/v1/nodes/2' => MockResponse::make([
            'data' => [
                'id' => 2,
                'name' => 'app-dev',
                'status' => 'active',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'roles' => ['app-dev'],
                'assignments' => [['id' => 1, 'role' => 'app-dev', 'status' => 'active', 'failed_step' => null, 'error_code' => null]],
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
                'assignments' => [['id' => 1, 'role' => 'app-dev', 'status' => 'active', 'failed_step' => null, 'error_code' => null]],
            ]],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);

    $this
        ->artisan('node:update', [
            'node' => '2',
            '--apps-path' => '/srv/orbit:data/apps',
            '--json' => true,
        ])
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(UpdateNodeRequest::class)
        ->and($request?->body()->all())
        ->toBe([
            'apps' => ['path' => '/srv/orbit:data/apps'],
        ]);
});

it('sends task VM host settings and their removal without changing apps', function (?array $host): void {
    node_update_profile();
    $mockClient = MockClient::global([
        '*/api/v1/nodes/9' => MockResponse::make([
            'data' => ['id' => 9, 'name' => 'beast', 'status' => 'active', 'public_ssh_host' => '192.0.2.9', 'settings' => $host === null ? null : ['task_vms' => $host]],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $this->artisan('node:update', ['node' => '9', '--task-vms' => json_encode($host), '--json' => true])->assertExitCode(0);
    expect($mockClient->getLastRequest()?->body()->all())->toEqual(['task_vms' => $host]);
})->with([
    'configured host' => [['cidr' => '10.251.77.0/24', 'task_places' => 4, 'ci_places' => 2, 'image_alias' => 'custom-base']],
    'CI-only loop pool' => [['cidr' => '10.252.10.0/24', 'task_places' => 0, 'ci_places' => 2, 'pool_size' => '512GiB']],
    'host with operator-owned resources' => [['cidr' => '10.251.77.0/24', 'task_places' => 4, 'ci_places' => 2, 'operator_owned' => ['orbit-ci/*', 'orbit-tasks/retained']]],
    'CI-only host' => [['cidr' => '10.251.77.0/24', 'task_places' => 6, 'ci_places' => 2]],
    'task-limited host' => [['cidr' => '10.251.77.0/24', 'task_places' => 12, 'ci_places' => 2]],
    'proof-only host' => [['cidr' => '10.251.77.0/24', 'task_places' => 12, 'ci_places' => 2]],
    'shared host' => [['cidr' => '10.251.77.0/24', 'task_places' => 12, 'ci_places' => 2]],
    'removed host' => [null],
]);

it('sends a null apps path for an empty --apps-path and the record fields beside it', function (): void {
    node_update_profile();
    $mockClient = MockClient::global([
        '*/api/v1/nodes/2' => MockResponse::make([
            'data' => ['id' => 2, 'name' => 'tablet', 'status' => 'active', 'platform' => 'ipados', 'public_ssh_host' => '10.44.0.12'],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);

    $this->artisan('node:update', ['node' => '2', '--name' => 'tablet', '--platform' => 'ipados', '--apps-path' => ''])
        ->expectsOutput('Node #2 [tablet] updated.')
        ->assertExitCode(0);

    expect($mockClient->getLastRequest()?->body()->all())
        ->toBe(['name' => 'tablet', 'platform' => 'ipados', 'apps' => ['path' => null]]);
});

it('refuses missing and malformed input before making a request', function (array $arguments, string $code): void {
    node_update_profile();
    $mockClient = MockClient::global();

    $this->artisan('node:update', [...$arguments, '--json' => true])
        ->expectsOutputToContain('"code":"'.$code.'"')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'missing node' => [['--name' => 'vpn'], 'node.reference_required'],
    'zero ID' => [['node' => '0', '--name' => 'vpn'], 'node.id_invalid'],
    'registered name' => [['node' => 'app-dev', '--name' => 'vpn'], 'node.id_invalid'],
    'fractional ID' => [['node' => '2.5', '--name' => 'vpn'], 'node.id_invalid'],
    'no option' => [['node' => '2'], 'node.option_required'],
    'empty name' => [['node' => '2', '--name' => ''], 'node.name_required'],
    'empty platform' => [['node' => '2', '--platform' => ''], 'node.platform_required'],
    'invalid host JSON' => [['node' => '2', '--task-vms' => '{'], 'node.setting_invalid'],
    'host is a list' => [['node' => '2', '--task-vms' => '[]'], 'node.setting_invalid'],
    'host unknown field' => [['node' => '2', '--task-vms' => '{"cidr":"10.251.77.0/24","max_vms":4,"unknown":true}'], 'node.setting_invalid'],
    'string task count' => [['node' => '2', '--task-vms' => '{"cidr":"10.251.77.0/24","max_vms":12,"task_max_vms":"2"}'], 'node.setting_invalid'],
    'string capacity' => [['node' => '2', '--task-vms' => '{"cidr":"10.251.77.0/24","max_vms":"4"}'], 'node.setting_invalid'],
    'non-string pool size' => [['node' => '2', '--task-vms' => '{"cidr":"10.252.10.0/24","max_vms":3,"pool_size":512}'], 'node.setting_invalid'],
    'non-string operator-owned resource' => [['node' => '2', '--task-vms' => '{"cidr":"10.251.77.0/24","max_vms":4,"operator_owned":["orbit-ci/*",7]}'], 'node.setting_invalid'],
    'operator-owned object instead of list' => [['node' => '2', '--task-vms' => '{"cidr":"10.251.77.0/24","max_vms":4,"operator_owned":{"resource":"orbit-ci/*"}}'], 'node.setting_invalid'],
]);

it('renders a Gateway refusal through the shared boundary', function (): void {
    node_update_profile();
    MockClient::global([
        UpdateNodeRequest::class => MockResponse::make(
            ['error' => ['code' => 'node.provisioning_busy', 'message' => 'Node [gateway] is already changing.']],
            409,
            ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ),
    ]);

    $this->artisan('node:update', ['node' => '1', '--name' => 'vpn', '--json' => true])
        ->expectsOutput('{"error":{"code":"node.provisioning_busy","message":"Node [gateway] is already changing.","request_id":"0198e15c-bf97-7c23-8f1f-61b8fe67a844"}}')
        ->assertExitCode(1);
});

it('follows an apps folder Operation and prints its final receipt in JSON', function (): void {
    node_update_profile();
    $operation = ['id' => 7, 'kind' => 'node.update', 'target_type' => 'node', 'target_id' => 2,
        'status' => 'succeeded', 'sequence' => 3, 'input' => [], 'steps' => [], 'result' => [],
        'finished_at' => '2026-10-28T12:00:00Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        UpdateNodeRequest::class => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);

    $this->artisan('node:update', ['node' => '2', '--apps-path' => '/srv/apps', '--idempotency-key' => 'update-proof', '--json' => true])
        ->expectsOutputToContain('node.update')->assertExitCode(0);
    $mock->assertSent(fn ($pending): bool => $pending instanceof UpdateNodeRequest
        && $pending->headers()->get('Idempotency-Key') === 'update-proof');
    $mock->assertSent(ShowOperationRequest::class);
});
it('renders an unchanged update without following an Operation', function (bool $json): void {
    node_update_profile();
    $mock = MockClient::global([
        UpdateNodeRequest::class => MockResponse::make([
            'data' => ['id' => 2, 'name' => 'tablet', 'status' => 'active', 'skipped' => 'node.unchanged'],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $this->artisan('node:update', ['node' => '2', '--name' => 'tablet', '--json' => $json])
        ->expectsOutputToContain($json ? '"skipped":"node.unchanged"' : 'Skipped — already set (node.unchanged).')
        ->assertExitCode(0);
    $mock->assertSentCount(1);
})->with([true, false]);
