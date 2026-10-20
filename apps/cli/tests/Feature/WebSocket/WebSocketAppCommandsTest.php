<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\WebSocket\AddInstanceWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\CreateWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\DestroyWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\ListWebSocketAppsRequest;
use Orbit\Sdk\Requests\WebSocket\RemoveInstanceWebSocketAppRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-websocket-app-'.Str::uuid();
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
});

function websocket_app_cli_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => ['id' => 2, 'name' => 'hauzer', 'app_id' => 'hauzerappid000000001', 'key' => 'hauzerappkey00000001', 'instance_ids' => []],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ], $status);
}

function websocket_attachment_cli_response(string $operation): MockResponse
{
    return MockResponse::make([
        'data' => [
            'instance_id' => 12,
            'name' => 'hauzer',
            'keys' => ['BROADCAST_CONNECTION', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME'],
            'operation' => $operation,
            'changed' => true,
            'key_count' => 7,
        ],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ]);
}

describe('websocket:app commands', function (): void {
    it('sends only the app name on create', function (): void {
        $mockClient = MockClient::global([CreateWebSocketAppRequest::class => websocket_app_cli_response(201)]);

        $this->artisan('websocket:app:create', ['name' => 'hauzer', '--json' => true])->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe(['name' => 'hauzer']);
    });

    it('refuses a name that is not a slug before sending', function (string $command, array $arguments): void {
        $mockClient = MockClient::global([]);
        $tester = new CommandTester(app(Kernel::class)->all()[$command]);

        expect($tester->execute([...$arguments, '--json' => true], ['interactive' => false]))->toBe(1)
            ->and($tester->getDisplay(true))->toContain('websocket.name_invalid')
            ->and($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'create' => ['websocket:app:create', ['name' => 'Hauzer']],
        'show' => ['websocket:app:show', ['name' => 'hauzer_app']],
        'destroy' => ['websocket:app:destroy', ['name' => 'hauzer_app', '--force' => true]],
        'long name' => ['websocket:app:create', ['name' => str_repeat('a', 64)]],
        'add' => ['instance:websocket:add', ['instance' => '12', 'app' => 'Hauzer']],
        'remove' => ['instance:websocket:remove', ['instance' => '12', 'app' => 'Hauzer', '--force' => true]],
    ]);

    it('refuses a JSON destroy without --force and destroys after it', function (): void {
        $mockClient = MockClient::global([DestroyWebSocketAppRequest::class => websocket_app_cli_response()]);
        $tester = new CommandTester(app(Kernel::class)->all()['websocket:app:destroy']);

        expect($tester->execute(['name' => 'hauzer', '--json' => true], ['interactive' => false]))->toBe(1)
            ->and($tester->getDisplay(true))->toContain('websocket.confirmation_required')
            ->and($mockClient->getLastPendingRequest())->toBeNull();

        $this->artisan('websocket:app:destroy', ['name' => 'hauzer', '--force' => true, '--json' => true])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())->toBeInstanceOf(DestroyWebSocketAppRequest::class);
    });
});

it('shows an empty app registry in human and JSON output', function (bool $json): void {
    MockClient::global([ListWebSocketAppsRequest::class => MockResponse::make([
        'data' => [],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ])]);
    $tester = new CommandTester(app(Kernel::class)->all()['websocket:app:list']);

    expect($tester->execute(['--json' => $json], ['interactive' => false]))->toBe(0);

    if ($json) {
        expect(json_decode($tester->getDisplay(true), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['apps' => [], 'request_id' => '11111111-1111-4111-8111-111111111111']);
    } else {
        expect($tester->getDisplay(true))->toContain('No WebSocket apps.', 'Request ID: 11111111-1111-4111-8111-111111111111');
    }
})->with(['human' => false, 'json' => true]);

it('preserves a Gateway refusal and request ID in clean JSON', function (string $command, array $arguments, string $requestClass, string $code, int $status): void {
    $requestId = '11111111-1111-4111-8111-111111111111';
    MockClient::global([$requestClass => MockResponse::make([
        'error' => ['code' => $code, 'message' => 'App request refused.'],
    ], $status, ['X-Orbit-Request-Id' => $requestId])]);
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    expect($tester->execute([...$arguments, '--json' => true], ['interactive' => false]))->toBe(1)
        ->and(json_decode($tester->getDisplay(true), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => ['code' => $code, 'message' => 'App request refused.', 'request_id' => $requestId],
        ]);
})->with([
    'reserved app' => ['instance:websocket:add', ['instance' => '12', 'app' => 'gateway'], AddInstanceWebSocketAppRequest::class, 'websocket.app_reserved', 409],
    'app in use' => ['websocket:app:destroy', ['name' => 'hauzer', '--force' => true], DestroyWebSocketAppRequest::class, 'websocket.app_in_use', 409],
    'missing attachment' => ['instance:websocket:remove', ['instance' => '12', 'app' => 'hauzer', '--force' => true], RemoveInstanceWebSocketAppRequest::class, 'websocket.attachment_missing', 404],
]);

it('requires consent in noninteractive human mode before sending a mutation', function (string $command, array $arguments): void {
    $mockClient = MockClient::global([]);
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    expect($tester->execute($arguments, ['interactive' => false]))->toBe(1)
        ->and($tester->getDisplay(true))->toContain('Use --force to confirm')
        ->and($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'destroy' => ['websocket:app:destroy', ['name' => 'hauzer']],
    'remove' => ['instance:websocket:remove', ['instance' => '12', 'app' => 'hauzer']],
]);

describe('instance:websocket commands', function (): void {
    it('adds an app on an Instance selected by its Route domain', function (): void {
        $mockClient = MockClient::global([AddInstanceWebSocketAppRequest::class => websocket_attachment_cli_response('attach')]);

        $this->artisan('instance:websocket:add', ['instance' => 'hauzer.test', 'app' => 'hauzer', '--json' => true])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/instances/hauzer.test/websocket-apps/hauzer');
    });

    it('refuses a JSON removal without --force and removes after it', function (): void {
        $mockClient = MockClient::global([RemoveInstanceWebSocketAppRequest::class => websocket_attachment_cli_response('detach')]);
        $tester = new CommandTester(app(Kernel::class)->all()['instance:websocket:remove']);

        expect($tester->execute(['instance' => '12', 'app' => 'hauzer', '--json' => true], ['interactive' => false]))->toBe(1)
            ->and($tester->getDisplay(true))->toContain('websocket.confirmation_required')
            ->and($mockClient->getLastPendingRequest())->toBeNull();

        $this->artisan('instance:websocket:remove', ['instance' => '12', 'app' => 'hauzer', '--force' => true, '--json' => true])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/instances/12/websocket-apps/hauzer');
    });
});
