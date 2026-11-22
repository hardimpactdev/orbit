<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
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
            'node' => 'app-dev',
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

it('sends only the CI members given, or null to clear them, beside the other fields', function (array $options, array $body): void {
    node_update_profile();
    $mockClient = MockClient::global([
        '*/api/v1/nodes/14' => MockResponse::make([
            'data' => ['id' => 14, 'name' => 'ci-node', 'status' => 'active', 'public_ssh_host' => '192.0.2.14', 'settings' => ['ci' => ['places' => 4, 'proof_only' => false]]],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);

    $this->artisan('node:update', ['node' => '14', ...$options])->expectsOutput('Node #14 [ci-node] updated.')->assertExitCode(0);

    expect($mockClient->getLastRequest()?->body()->all())->toBe($body);
})->with([
    'places' => [['--ci-places' => '4'], ['ci' => ['places' => 4]]],
    'zero places' => [['--ci-places' => '0'], ['ci' => ['places' => 0]]],
    'places for proof copies only' => [['--ci-places' => '2', '--ci-proof-only' => true], ['ci' => ['places' => 2, 'proof_only' => true]]],
    'proof only alone' => [['--ci-proof-only' => true], ['ci' => ['proof_only' => true]]],
    'shared with runner jobs' => [['--no-ci-proof-only' => true], ['ci' => ['proof_only' => false]]],
    'cleared' => [['--no-ci' => true], ['ci' => null]],
    'with a new name' => [['--name' => 'ci-node', '--ci-places' => '3'], ['name' => 'ci-node', 'ci' => ['places' => 3]]],
]);

it('renders the Gateway refusal of CI places on a Node without an active ci-runner role', function (): void {
    node_update_profile();
    MockClient::global([
        UpdateNodeRequest::class => MockResponse::make(
            ['error' => ['code' => 'node.settings_ci_runner_required', 'message' => 'Only a Node with an active ci-runner role takes CI places.']],
            422,
            ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ),
    ]);

    $this->artisan('node:update', ['node' => '2', '--ci-places' => '2', '--json' => true])
        ->expectsOutputToContain('"code":"node.settings_ci_runner_required"')
        ->assertExitCode(1);
});

it('reports a skip when the Gateway wrote nothing, in human and JSON output', function (): void {
    node_update_profile();
    MockClient::global([
        '*/api/v1/nodes/9' => MockResponse::make([
            'data' => ['id' => 9, 'name' => 'beast', 'status' => 'active', 'public_ssh_host' => '192.0.2.9'],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844', 'skipped' => 'node.unchanged'],
        ]),
    ]);

    $this->artisan('node:update', ['node' => '9', '--name' => 'beast'])
        ->expectsOutputToContain('Skipped — already set (node.unchanged)')
        ->doesntExpectOutputToContain('updated.')
        ->assertExitCode(0);
    $this->artisan('node:update', ['node' => '9', '--name' => 'beast', '--json' => true])
        ->expectsOutputToContain('"skipped":"node.unchanged"')
        ->assertExitCode(0);
});

it('reports no skip for a saved change', function (): void {
    node_update_profile();
    MockClient::global([
        '*/api/v1/nodes/9' => MockResponse::make([
            'data' => ['id' => 9, 'name' => 'beast', 'status' => 'active', 'public_ssh_host' => '192.0.2.9'],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);

    $this->artisan('node:update', ['node' => '9', '--name' => 'beast', '--json' => true])
        ->doesntExpectOutputToContain('skipped')
        ->assertExitCode(0);
});

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
    'no option' => [['node' => '2'], 'node.option_required'],
    'empty name' => [['node' => '2', '--name' => ''], 'node.name_required'],
    'empty platform' => [['node' => '2', '--platform' => ''], 'node.platform_required'],
    'CI places not a number' => [['node' => '14', '--ci-places' => 'four'], 'node.ci_places_invalid'],
    'empty CI places' => [['node' => '14', '--ci-places' => ''], 'node.ci_places_invalid'],
    'negative CI places' => [['node' => '14', '--ci-places' => '-1'], 'node.ci_places_invalid'],
    'too many CI places' => [['node' => '14', '--ci-places' => '65'], 'node.ci_places_invalid'],
    'clear with CI places' => [['node' => '14', '--no-ci' => true, '--ci-places' => '2'], 'node.ci_option_conflict'],
    'clear with proof only' => [['node' => '14', '--no-ci' => true, '--ci-proof-only' => true], 'node.ci_option_conflict'],
    'clear with shared places' => [['node' => '14', '--no-ci' => true, '--no-ci-proof-only' => true], 'node.ci_option_conflict'],
    'both proof-only switches' => [['node' => '14', '--ci-proof-only' => true, '--no-ci-proof-only' => true], 'node.ci_option_conflict'],
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

it('sets and clears a reboot hold through the existing Node update request', function (bool $hold): void {
    node_update_profile();
    $mock = MockClient::global(['*/api/v1/nodes/9' => MockResponse::make([
        'data' => ['id' => 9, 'name' => 'beast', 'status' => 'active', 'reboot_hold' => $hold],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ])]);
    $this->artisan('node:update', ['node' => '9', '--reboot-hold' => $hold ? 'true' : 'false', '--json' => true])
        ->expectsOutputToContain('"reboot_hold":'.($hold ? 'true' : 'false'))->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['reboot_hold' => $hold]);
})->with([true, false]);

it('refuses malformed reboot hold options before sending a request', function (string $value): void {
    $this->artisan('node:update', ['node' => '9', '--reboot-hold' => $value, '--json' => true])
        ->expectsOutputToContain('node.reboot_hold_invalid')->assertExitCode(1);
})->with(['', '1', 'yes', 'null']);

it('sets and clears primary_agent through the existing Node update request', function (bool $primary): void {
    node_update_profile();
    $mock = MockClient::global(['*/api/v1/nodes/11' => MockResponse::make([
        'data' => ['id' => 11, 'name' => 'mini', 'status' => 'active', 'primary_agent' => $primary],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ])]);
    $this->artisan('node:update', ['node' => '11', '--primary-agent' => $primary ? 'true' : 'false', '--json' => true])
        ->expectsOutputToContain('"primary_agent":'.($primary ? 'true' : 'false'))->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['primary_agent' => $primary]);
})->with([true, false]);

it('refuses malformed primary_agent options before sending a request', function (string $value): void {
    $this->artisan('node:update', ['node' => '11', '--primary-agent' => $value, '--json' => true])
        ->expectsOutputToContain('node.primary_agent_invalid')->assertExitCode(1);
})->with(['', '1', 'yes', 'null']);

it('refuses the removed task VM and image refresh hold options by name and sends no request', function (string $option): void {
    node_update_profile();
    $mock = MockClient::global();
    expect(Artisan::call('node:update', ['node' => '12', $option => $option === '--task-vms' ? '{}' : true, '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toMatchArray(['code' => 'input.invalid', 'message' => "The \"{$option}\" option does not exist."]);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with(['--task-vms', '--image-refresh-hold', '--no-image-refresh-hold']);
