<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\CloneInstanceRequest;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Orbit\Sdk\Requests\Instances\RenameInstanceRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-lifecycle-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/unused-ca'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('follows lifecycle admission and emits only the final Operation in JSON', function (string $command, string $request, array $arguments, array $body): void {
    $kind = str_replace(':', '.', $command);
    $queued = ['id' => 901, 'kind' => $kind, 'target_type' => 'instance', 'target_id' => 501,
        'status' => 'queued', 'sequence' => 1, 'poll_after_seconds' => 0];
    $final = [...$queued, 'status' => 'succeeded', 'sequence' => 8, 'result' => ['instance_id' => 501, 'lifecycle_complete' => true]];
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $mock = MockClient::global([
        $request => MockResponse::make(['data' => $queued, 'meta' => ['request_id' => $requestId]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final, 'meta' => ['request_id' => $requestId]]),
    ]);
    $exit = Artisan::call($command, [...$arguments, '--idempotency-key' => 'lifecycle-proof', '--json' => true, '--no-interaction' => true]);
    $output = trim(Artisan::output());
    $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($data['id'])->toBe(901)->and($data['kind'])->toBe($kind)
        ->and($data['status'])->toBe('succeeded')->and($data['result']['instance_id'])->toBe(501)
        ->and($output)->not->toContain("\033", 'Waiting for');
    $mock->assertSent(fn ($pending): bool => $pending instanceof $request
        && $pending->body()->all() === $body && $pending->headers()->get('Idempotency-Key') === 'lifecycle-proof');
    $mock->assertSent(ShowOperationRequest::class);
})->with([
    'create' => ['instance:create', CreateInstanceRequest::class, ['project' => 50, 'node' => 9, 'name' => 'proof'],
        ['project_id' => 50, 'node_id' => 9, 'name' => 'proof']],
    'clone' => ['instance:clone', CloneInstanceRequest::class, ['candidate' => 501, 'node' => 10, 'name' => 'prod', '--preview-name' => 'preview'],
        ['node_id' => 10, 'name' => 'prod', 'preview_name' => 'preview']],
    'rename' => ['instance:rename', RenameInstanceRequest::class, ['instance' => 501, '--branch' => 'feature'], ['branch' => 'feature']],
    'destroy' => ['instance:destroy', DestroyInstanceRequest::class, ['instance' => 501, '--yes' => true, '--force' => true], ['force' => true]],
]);
