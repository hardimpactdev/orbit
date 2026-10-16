<?php

declare(strict_types=1);

use App\Commands\Tasks\ListTaskGroupsCommand;
use App\Commands\Tasks\ShowTaskGroupCommand;
use App\Commands\Tasks\ShowTasksStatusCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use App\Support\ExtensionCommandVisibility;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    GatewayExtensionState::reset();
    $this->callerOrbitHome = config('orbit.home');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tasks-ext-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test', url: 'https://10.44.0.1', caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    GatewayExtensionState::reset();
    new Filesystem()->deleteDirectory($this->orbitHome);
    config()->set('orbit.home', $this->callerOrbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

describe('Tasks extension calls', function (): void {
    it('hides commands after failed discovery but still sends direct calls', function (string $failure): void {
        $discoveryCalls = 0;
        $mock = MockClient::global([
            ...gateway_fixture_mock('tasks/tasks-list/default'),
            ListExtensionsRequest::class => static function (PendingRequest $request) use ($failure, &$discoveryCalls): MockResponse {
                $discoveryCalls++;
                if ($failure === 'access denied') {
                    return MockResponse::make(['error' => ['code' => 'node_access.required', 'message' => 'Node access is required.']], 403);
                }

                throw new FatalRequestException(new RuntimeException($failure === 'timeout'
                    ? 'cURL error 28: Operation timed out after 2000 milliseconds'
                    : 'cURL error 7: Failed to connect'), $request);
            },
        ]);

        foreach ([ListTaskGroupsCommand::class, ShowTaskGroupCommand::class] as $class) {
            $command = app($class);
            expect($command->extensionSlug())->toBe('tasks')
                ->and($command->isHidden())->toBeFalse()
                ->and(ExtensionCommandVisibility::duringListing(static fn (): bool => $command->isHidden()))->toBeTrue();
        }
        expect(app(GatewayExtensionState::class)->discover()->isKnown())->toBeFalse();
        $status = app(ShowTasksStatusCommand::class);
        expect($status->extensionSlug())->toBeNull()
            ->and(ExtensionCommandVisibility::duringListing(static fn (): bool => $status->isHidden()))->toBeFalse();

        expect(Artisan::call('tasks:list', ['--json' => true]))->toBe(0)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('task_groups')
            ->and($discoveryCalls)->toBe(1);
        $mock->assertSentCount(1, ListTaskGroupsRequest::class);
    })->with(['access denied', 'timeout', 'network error']);

    it('sends a direct call without requesting extension switches', function (): void {
        $mock = MockClient::global(gateway_fixture_mock('tasks/tasks-list/default'));

        expect(Artisan::call('tasks:list', ['--json' => true]))->toBe(0)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('task_groups');
        $mock->assertSentCount(0, ListExtensionsRequest::class);
        $mock->assertSentCount(1, ListTaskGroupsRequest::class);
    });

    it('renders the Gateway refusal without discovering extension switches', function (int $status, string $code, string $message): void {
        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ];
        $mock = MockClient::global([
            ListTaskGroupsRequest::class => MockResponse::make(['error' => $error], $status, ['X-Orbit-Request-Id' => $error['request_id']]),
        ]);

        expect(Artisan::call('tasks:list', ['--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(['error' => $error]);
        $mock->assertSentCount(0, ListExtensionsRequest::class);
        $mock->assertSentCount(1, ListTaskGroupsRequest::class);
    })->with([
        'disabled' => [409, 'extension.disabled', 'The tasks extension is disabled.'],
        'node access required' => [403, 'node_access.required', 'Node access is required.'],
    ]);
});
