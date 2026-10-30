<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-analytics-update-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->requestId = (string) Str::uuid();
    $this->answer = fn (string $version, string $previous): MockResponse => MockResponse::make([
        'data' => ['id' => 42, 'kind' => 'analytics.update', 'target_type' => 'node', 'target_id' => 17, 'input' => [], 'status' => 'succeeded', 'sequence' => 3, 'current_step' => null, 'failed_step' => null, 'error_code' => null, 'result' => ['ready' => true, 'version' => $version, 'previous_version' => $previous], 'cancel_requested' => false, 'attempts' => 0, 'triggered_by' => 'operator', 'created_at' => '2026-10-30T10:00:00Z', 'started_at' => '2026-10-30T10:00:01Z', 'finished_at' => '2026-10-30T10:00:02Z', 'poll_after_seconds' => 2],
        'meta' => ['request_id' => $this->requestId],
    ], 202);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('analytics:update', function (): void {
    it('sends the version and returns the Gateway answer as JSON', function (): void {
        $mockClient = MockClient::global([UpdateAnalyticsRequest::class => ($this->answer)('3.3.0', '3.2.1'), ShowOperationRequest::class => ($this->answer)('3.3.0', '3.2.1'), ShowRealtimeRequest::class => MockResponse::make(['data' => ['enabled' => false]])]);

        $this->artisan('analytics:update', ['version' => '3.3.0', '--json' => true])
            ->expectsOutputToContain('"previous_version":"3.2.1"')
            ->assertExitCode(0);

        $mockClient->assertSent(static fn ($request): bool => $request instanceof UpdateAnalyticsRequest && $request->body()->all() === ['version' => '3.3.0']);
    });

    it('names the node and both versions for a human', function (): void {
        MockClient::global([UpdateAnalyticsRequest::class => ($this->answer)('3.3.0', '3.2.1'), ShowOperationRequest::class => ($this->answer)('3.3.0', '3.2.1'), ShowRealtimeRequest::class => MockResponse::make(['data' => ['enabled' => false]])]);

        $this->artisan('analytics:update', ['version' => '3.3.0'])
            ->expectsOutputToContain('Plausible succeeded.')
            ->assertExitCode(0);
    });

    it('says so when that version already runs', function (): void {
        MockClient::global([UpdateAnalyticsRequest::class => ($this->answer)('3.2.1', '3.2.1'), ShowOperationRequest::class => ($this->answer)('3.2.1', '3.2.1'), ShowRealtimeRequest::class => MockResponse::make(['data' => ['enabled' => false]])]);

        $this->artisan('analytics:update', ['version' => '3.2.1'])
            ->expectsOutputToContain('Plausible succeeded.')
            ->assertExitCode(0);
    });

    it('refuses a missing or malformed version before any request', function (array $arguments): void {
        $mockClient = MockClient::global();

        $this->artisan('analytics:update', [...$arguments, '--json' => true])
            ->expectsOutputToContain('analytics.version_invalid')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'missing' => [[]],
        'v prefix' => [['version' => 'v3.3.0']],
        'two numbers' => [['version' => '3.3']],
        'a tag' => [['version' => 'latest']],
    ]);
});
