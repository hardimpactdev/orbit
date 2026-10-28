<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Processes\CreateProcessRequest;
use Orbit\Sdk\Requests\Processes\DestroyProcessRequest;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;
use Orbit\Sdk\Requests\Processes\StartProcessRequest;
use Orbit\Sdk\Requests\Processes\StopProcessRequest;
use Orbit\Sdk\Requests\Processes\UpdateProcessRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Schedules\CreateScheduleRequest;
use Orbit\Sdk\Requests\Schedules\DestroyScheduleRequest;
use Orbit\Sdk\Requests\Schedules\EnableScheduleRequest;
use Orbit\Sdk\Requests\Schedules\RunScheduleRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->operationHome = sys_get_temp_dir().'/orbit-cli-lane9-'.Str::uuid();
    config()->set('orbit.home', $this->operationHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://10.44.0.1', caPath: '/unused/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->operationHome);
});

it('follows each installed write and preserves the admission key', function (string $command, string $requestClass, array $arguments): void {
    $rid = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $data = ['id' => 91, 'kind' => str_replace(':', '.', $command), 'target_type' => 'process', 'target_id' => 12, 'input' => [], 'status' => 'succeeded', 'sequence' => 3, 'cancel_requested' => false, 'attempts' => 1, 'result' => [], 'steps' => []];
    $mock = MockClient::global([
        $requestClass => MockResponse::make(['data' => [...$data, 'status' => 'queued'], 'meta' => ['request_id' => $rid]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => $rid]]),
    ]);
    $expected = json_encode(OperationResponse::fromGatewayData($data, $rid)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $this->artisan($command, [...$arguments, '--json' => true, '--idempotency-key' => 'lane9-proof'])
        ->expectsOutput($expected)->assertExitCode(0);
    $mock->assertSent(fn ($request): bool => $request instanceof $requestClass && $request->headers()->get('Idempotency-Key') === 'lane9-proof');
    $mock->assertSent(fn ($request): bool => $request instanceof ShowOperationRequest);
})->with([
    ['process:create', CreateProcessRequest::class, ['name' => 'proof', '--node' => '2', '--command' => ['/usr/bin/sleep', '300']]],
    ['process:update', UpdateProcessRequest::class, ['name' => '12', '--keep-alive' => true]],
    ['process:start', StartProcessRequest::class, ['process' => '12']],
    ['process:stop', StopProcessRequest::class, ['process' => '12']],
    ['process:restart', RestartProcessRequest::class, ['process' => '12']],
    ['process:destroy', DestroyProcessRequest::class, ['process' => '12', '--yes' => true]],
    ['schedule:create', CreateScheduleRequest::class, ['name' => 'proof', '--node' => '2', '--calendar' => 'daily', '--command' => 'true']],
    ['schedule:run', RunScheduleRequest::class, ['schedule' => '830fd68c-948b-4e46-a215-229c7f510246']],
    ['schedule:enable', EnableScheduleRequest::class, ['schedule' => '830fd68c-948b-4e46-a215-229c7f510246']],
    ['schedule:destroy', DestroyScheduleRequest::class, ['schedule' => '830fd68c-948b-4e46-a215-229c7f510246', '--yes' => true]],
]);
