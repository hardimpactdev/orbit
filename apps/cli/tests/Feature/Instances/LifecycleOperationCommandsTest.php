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
use Orbit\Sdk\Requests\Instances\SleepInstanceRequest;
use Orbit\Sdk\Requests\Instances\TransferInstanceRequest;
use Orbit\Sdk\Requests\Instances\WakeInstanceRequest;
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
    'sleep' => ['instance:sleep', SleepInstanceRequest::class, ['instance' => 501], []],
    'wake' => ['instance:wake', WakeInstanceRequest::class, ['instance' => 501], []],
]);

it('says in human output that a sleep did not check T3', function (): void {
    $final = ['id' => 901, 'kind' => 'instance.sleep', 'target_type' => 'instance', 'target_id' => 501, 'status' => 'succeeded', 'sequence' => 4,
        'result' => ['power_state' => 'stopped', 't3_turn' => 'not_checked']];
    MockClient::global([
        SleepInstanceRequest::class => MockResponse::make(['data' => $final], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final]),
    ]);

    $exit = Artisan::call('instance:sleep', ['instance' => 501, '--no-interaction' => true]);

    expect($exit)->toBe(0)->and(str_replace(["\n", "\r"], ' ', Artisan::output()))
        ->toContain('T3 was not checked: a running agent turn, if any, was cut.');
});

it('names blockers in human and JSON migration failures', function (bool $json, string $code, string $message): void {
    $final = ['id' => 901, 'kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => 501,
        'status' => 'failed', 'sequence' => 8, 'error_code' => $code,
        'result' => ['instance_id' => 501, 'moved' => false, 'error_message' => $message]];
    MockClient::global([
        MigrateInstanceRequest::class => MockResponse::make(['data' => $final], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final]),
    ]);

    $exit = Artisan::call('instance:migrate', ['instance' => 501, '--force' => true, '--json' => $json, '--no-interaction' => true]);
    $output = trim(Artisan::output());

    expect($exit)->toBe(1)->and($output)->not->toContain('DB_PASSWORD', 'secret-password');
    if ($json) {
        $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($data['result']['error_message'])->toBe($message)->and($data['error_code'])->toBe($code);
    } else {
        expect(str_replace(["\n", "\r"], '', $output))->toContain('/fixture', $code);
        expect(preg_replace('/\s+/', '', $output))->toContain(preg_replace('/\s+/', '', $message));
    }
})->with(['human' => false, 'json' => true])->with([
    'worktrees and files' => ['instance.migrate_retirement_blocked', 'Cannot retire checkout "/fixture". Other worktrees: /detached. Changed files: index.php.'],
    'unpushed commits' => ['instance.migrate_unpushed_commits', 'Cannot retire checkout "/fixture". Branch spare has 2 unpushed commits.'],
    'stashes' => ['instance.migrate_stashes_present', 'Cannot retire checkout "/fixture". Stashes: 2.'],
    'symlinks' => ['instance.migrate_symlink_unsupported', 'Cannot migrate checkout "/fixture". Unsupported symlinks: [{"path":"public/storage","target":"/missing/storage","reason":"broken"},{"path":"local-package","target":"/shared/package","reason":"outside checkout"}].'],
    'service hosts' => ['instance.migrate_env_service_unreachable', 'Cannot migrate /fixture/.env: DB_HOST=127.0.0.1:3308 is not reachable from the container. Attach the target, correct the .env, or use a non-network driver.'],
]);

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

it('shows migration mail outcomes and advisory warnings in human and JSON output', function (bool $json, array $mail, string $human): void {
    $result = ['instance_id' => 501, 'moved' => true, 'ready' => true, ...$mail];
    $final = ['id' => 901, 'kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => 501,
        'status' => 'succeeded', 'sequence' => 8, 'result' => $result];
    MockClient::global([
        MigrateInstanceRequest::class => MockResponse::make(['data' => $final], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final]),
    ]);
    expect(Artisan::call('instance:migrate', ['instance' => 501, '--force' => true, '--json' => $json, '--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();
    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'])->toBe($result);
        expect($output)->not->toContain('Warning:');
    } else {
        expect(preg_replace('/\s+/', '', $output))->toContain(preg_replace('/\s+/', '', $human));
        expect(substr_count($output, str_starts_with($human, 'Warning:') ? 'Warning:' : $human))->toBe(1);
    }
})->with(['human' => false, 'json' => true])->with([
    'changed' => [['mailer' => ['status' => 'changed', 'lines' => [50]]], 'Mailer: changed (line 50).'],
    'preserved' => [['mailer' => ['status' => 'left_as_written', 'lines' => [4, 9]]], 'Mailer: left_as_written (line 4, 9).'],
    'missing' => [['mailer' => ['status' => 'not_present']], 'Mailer: not_present.'],
    'smtp' => [['mailer' => ['status' => 'already_smtp']], 'Mailer: already_smtp.'],
    'port conflict' => [['mail' => ['status' => 'skipped', 'reason' => 'mail_port_in_use']], 'Mail setup skipped: mail_port_in_use.'],
    'unit conflict' => [['mail' => ['status' => 'skipped', 'reason' => 'mail_unit_conflict']], 'Mail setup skipped: mail_unit_conflict.'],
    'unused environment endpoint' => [['warnings' => [['code' => 'instance.migrate_env_service_unused',
        'message' => '.env: DB_HOST=127.0.0.1:3306: no TCP service answered on the Node.']]],
        'Warning: instance.migrate_env_service_unused. .env: DB_HOST=127.0.0.1:3306: no TCP service answered on the Node.'],
    'generic environment warning' => [['warnings' => [['code' => 'instance.future_environment_warning', 'message' => 'A safe future warning.']]],
        'Warning: instance.future_environment_warning. A safe future warning.'],
    'advisory' => [['warnings' => [['code' => 'instance.mail_update_failed', 'step' => 'mail']]],
        'Warning: instance.mail_update_failed. Run orbit instance:mail:update 501 to repair mail.'],
]);

it('reports copied untracked files for humans and keeps only the final Operation in JSON', function (bool $json, int $count): void {
    $result = ['instance_id' => 501, 'moved' => true, 'ready' => true, 'untracked_files_copied' => $count];
    $final = ['id' => 901, 'kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => 501,
        'status' => 'succeeded', 'sequence' => 8, 'result' => $result];
    MockClient::global([
        MigrateInstanceRequest::class => MockResponse::make(['data' => $final], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final]),
    ]);

    expect(Artisan::call('instance:migrate', ['instance' => 501, '--force' => true, '--json' => $json, '--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();
    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'])->toBe($result);
        expect($output)->not->toContain('Copied ');
    } else {
        $message = 'Copied '.$count.' untracked files that Git does not ignore.';
        expect($output)->toContain($message)->and(substr_count($output, $message))->toBe(1);
    }
})->with(['human' => false, 'json' => true])->with(['none' => 0, 'generated files' => 56]);
