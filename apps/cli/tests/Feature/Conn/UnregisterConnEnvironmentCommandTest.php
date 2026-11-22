<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Conn\UnregisterConnEnvironmentRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const UnregisterRequestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-conn-unregister-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/unused-ca'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('unregisters a host Conn environment in JSON and human modes', function (bool $removed, string $expectedHuman): void {
    $envId = '2f6cdbde-0000-4000-8000-000000000001';
    $mock = MockClient::global([
        UnregisterConnEnvironmentRequest::class => MockResponse::make([
            'data' => ['environment_id' => $envId, 'removed' => $removed],
            'meta' => ['request_id' => UnregisterRequestId],
        ]),
    ]);

    expect(Artisan::call('conn:environment:unregister', ['environment' => $envId, '--json' => true, '--no-interaction' => true]))->toBe(0)
        ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['environment_id' => $envId, 'removed' => $removed]);

    expect(Artisan::call('conn:environment:unregister', ['environment' => $envId, '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain($expectedHuman);

    $mock->assertSent(fn ($request): bool => $request instanceof UnregisterConnEnvironmentRequest
        && $request->resolveEndpoint() === '/api/v1/conn/environments/'.$envId);
})->with([
    'removed' => [true, 'Unregistered Conn environment 2f6cdbde-0000-4000-8000-000000000001.'],
    'not registered' => [false, 'Conn environment 2f6cdbde-0000-4000-8000-000000000001 was not registered.'],
]);

it('refuses an empty environment id before calling the Gateway', function (): void {
    $mock = MockClient::global([]);

    expect(Artisan::call('conn:environment:unregister', ['environment' => '   ', '--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('conn.environment_id_invalid');

    $mock->assertNothingSent();
});
