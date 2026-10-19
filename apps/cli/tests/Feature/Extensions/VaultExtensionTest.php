<?php

declare(strict_types=1);

use App\Commands\Vault\StatusVaultCommand;
use App\Commands\Vault\SyncVaultCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Support\ExtensionCommandVisibility;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Orbit\Sdk\Requests\Vault\ShowVaultStatusRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    GatewayExtensionState::reset();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-vault-ext-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test', url: 'https://10.44.0.1', caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('hides the vault commands according to the Gateway vault switch', function (bool $enabled): void {
    MockClient::global([ListExtensionsRequest::class => MockResponse::make([
        'data' => ['tasks' => false, 'ai-router' => false, 'documents' => false, 'vault' => $enabled, 'conn' => false],
        'meta' => ['request_id' => 'extensions-id'],
    ])]);

    foreach ([StatusVaultCommand::class, SyncVaultCommand::class] as $class) {
        $command = app($class);
        expect($command->extensionSlug())->toBe('vault')
            ->and($command->isHidden())->toBeFalse()
            ->and(ExtensionCommandVisibility::duringListing(static fn (): bool => $command->isHidden()))
            ->toBe(! $enabled);
    }
})->with(['disabled' => [false], 'enabled' => [true]]);

it('hides commands after failed discovery but leaves direct refusals to the Gateway', function (string $failure): void {
    $discoveryCalls = 0;
    $error = [
        'code' => 'extension.disabled',
        'message' => 'The vault extension is disabled.',
        'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    ];
    $mock = MockClient::global([
        ListExtensionsRequest::class => static function (PendingRequest $request) use ($failure, &$discoveryCalls): MockResponse {
            $discoveryCalls++;
            if ($failure === 'access denied') {
                return MockResponse::make(['error' => ['code' => 'node.access_denied', 'message' => 'Node access denied.']], 403);
            }

            throw new FatalRequestException(new RuntimeException($failure === 'timeout'
                ? 'cURL error 28: Operation timed out after 2000 milliseconds'
                : 'cURL error 7: Failed to connect'), $request);
        },
        ShowVaultStatusRequest::class => MockResponse::make(['error' => $error], 409, ['X-Orbit-Request-Id' => $error['request_id']]),
    ]);

    $command = app(StatusVaultCommand::class);
    expect($command->isHidden())->toBeFalse()
        ->and(ExtensionCommandVisibility::duringListing(static fn (): bool => $command->isHidden()))->toBeTrue()
        ->and(app(GatewayExtensionState::class)->discover()->isKnown())->toBeFalse();

    expect(Artisan::call('vault:status', ['--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(['error' => $error])
        ->and($discoveryCalls)->toBe(1);
    $mock->assertSentCount(1, ShowVaultStatusRequest::class);
})->with(['access denied', 'timeout', 'network error']);
