<?php

declare(strict_types=1);

use App\Commands\ProjectDocuments\ListProjectDocumentCommand;
use App\Commands\ProjectDocuments\ShowProjectDocumentStorageCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Support\ExtensionCommandVisibility;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Orbit\Sdk\Requests\ProjectDocuments\ShowProjectDocumentStorageRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    GatewayExtensionState::reset();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-documents-ext-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test', url: 'https://10.44.0.1', caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('hides the Project Document commands according to the Gateway documents switch', function (bool $enabled): void {
    MockClient::global([ListExtensionsRequest::class => MockResponse::make([
        'data' => ['tasks' => false, 'proxycli' => false, 'documents' => $enabled], 'meta' => ['request_id' => 'extensions-id'],
    ])]);

    foreach ([ListProjectDocumentCommand::class, ShowProjectDocumentStorageCommand::class] as $class) {
        $command = app($class);
        expect($command->extensionSlug())->toBe('documents')
            ->and($command->isHidden())->toBeFalse()
            ->and(ExtensionCommandVisibility::duringListing(static fn (): bool => $command->isHidden()))
            ->toBe(! $enabled);
    }
})->with(['disabled' => [false], 'enabled' => [true]]);

it('sends no document request while the documents switch is unknown', function (): void {
    $mock = MockClient::global([ListExtensionsRequest::class => static function (PendingRequest $request): never {
        throw new FatalRequestException(new RuntimeException('cURL error 7: Failed to connect'), $request);
    }]);

    expect(Artisan::call('project:document-storage:show', ['--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('extension.state_unknown');
    $mock->assertNotSent(ShowProjectDocumentStorageRequest::class);
});
