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
use Orbit\Sdk\Requests\Instances\MigrateInstanceRequest;
use Orbit\Sdk\Requests\Instances\TransferInstanceRequest;
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
    'transfer' => ['instance:transfer', TransferInstanceRequest::class, ['instance' => 501, 'node' => 10, '--force' => true], ['node_id' => 10]],
    'migrate' => ['instance:migrate', MigrateInstanceRequest::class, ['instance' => 501, '--force' => true], []],
    'destroy' => ['instance:destroy', DestroyInstanceRequest::class, ['instance' => 501, '--yes' => true, '--force' => true], ['force' => true]],
]);

it('names retirement blockers in human and JSON migration failures', function (bool $json): void {
    $message = 'Cannot retire checkout "/fixture". Other worktrees (1 total; first 20): ["/detached"]. Changed files (1 total; first 20): [" M index.php"].';
    $final = ['id' => 901, 'kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => 501,
        'status' => 'failed', 'sequence' => 8, 'error_code' => 'instance.migrate_retirement_blocked',
        'result' => ['instance_id' => 501, 'moved' => false, 'error_message' => $message]];
    MockClient::global([
        MigrateInstanceRequest::class => MockResponse::make(['data' => $final], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final]),
    ]);

    $exit = Artisan::call('instance:migrate', ['instance' => 501, '--force' => true, '--json' => $json, '--no-interaction' => true]);
    $output = trim(Artisan::output());

    expect($exit)->toBe(1);
    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result']['error_message'])->toBe($message);
    } else {
        expect(str_replace(["\n", "\r"], '', $output))->toContain('/fixture', '/detached', 'index.php', 'instance.migrate_retirement_blocked');
    }
})->with(['human' => false, 'json' => true]);

it('renders every final clone warning once for humans and retains the JSON list', function (bool $json): void {
    $warnings = ['REDIS_HOST: Review with env:update, then env:sync.', 'ASSET_URL: Review with env:update, then env:sync.'];
    $queued = ['id' => 901, 'kind' => 'instance.clone', 'target_type' => 'instance', 'target_id' => 501,
        'status' => 'queued', 'sequence' => 1, 'poll_after_seconds' => 0];
    $result = ['instance_id' => 501, 'ready' => true, 'branch' => 'main', 'commit' => str_repeat('a', 40),
        'php_version' => '8.5', 'laravel' => true, 'candidate_commit' => str_repeat('a', 40),
        'environment_keys' => ['REDIS_HOST', 'ASSET_URL'], 'warnings' => $warnings];
    $final = [...$queued, 'status' => 'succeeded', 'sequence' => 8, 'poll_after_seconds' => null, 'result' => $result];
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $mock = MockClient::global([
        CloneInstanceRequest::class => MockResponse::make(['data' => $queued, 'meta' => ['request_id' => $requestId]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final, 'meta' => ['request_id' => $requestId]]),
    ]);

    expect(Artisan::call('instance:clone', ['candidate' => 501, 'node' => 10, 'name' => 'prod',
        '--preview-name' => 'preview', '--json' => $json, '--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'])->toBe($result);
        expect($output)->not->toContain('Warning:');
    } else {
        expect($output)->toContain('Warning: '.$warnings[0]."\n".'Warning: '.$warnings[1]."\n".'Operation: 901');
        foreach ($warnings as $warning) {
            expect(substr_count($output, $warning))->toBe(1);
        }
        expect($output)->toContain('Request ID: '.$requestId);
    }
    $mock->assertSentCount(1, CloneInstanceRequest::class);
    $mock->assertSentCount($json ? 2 : 1, ShowOperationRequest::class);
})->with(['human' => false, 'json' => true]);
