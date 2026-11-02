<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\InstanceEnvironmentImporter;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentImportProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

it('stores a usable Laravel key before sync when importing an empty APP_KEY', function (bool $production, ?string $stored): void {
    $instance = empty_app_key_import_fixture($production);
    if ($stored !== null) {
        $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => $stored]);
    }

    [$operation, $socket] = native_env_import($instance, "APP_KEY=\nAPP_ENV=local\n", true);

    $key = $instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value;
    expect($key)->toStartWith('base64:');
    $decoded = base64_decode(substr($key, 7), strict: true);
    expect($decoded)->toBeString();
    expect(strlen($decoded))->toBe(32);
    $encrypter = new Encrypter($decoded, 'AES-256-CBC');
    expect($encrypter->decryptString($encrypter->encryptString('usable')))->toBe('usable');
    expect($operation->status->value)->toBe('succeeded')->and($operation->result['changed'])->toBeTrue()
        ->and(collect($socket->sent)->pluck('type')->all())->toBe(['vault.read-secret', 'vault.delete'])
        ->and(json_encode($operation->toArray()))->not->toContain('APP_ENV=local');
})->with([
    'e2e-dev import' => [false, null],
    'e2e-prod import' => [true, null],
    'repair stored empty key' => [true, ''],
]);

it('does not rotate a stored key when replacing an empty APP_KEY', function (): void {
    $instance = empty_app_key_import_fixture(false);
    $stored = 'base64:'.base64_encode(str_repeat('k', 32));
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => $stored]);

    native_env_import($instance, "APP_KEY=\n", true);
    [$operation] = native_env_import($instance, "APP_KEY=\n", true);

    expect($instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)->toBe($stored);
    expect($operation->result['changed'])->toBeFalse();
});

it('keeps normal import conflict semantics for an empty APP_KEY', function (): void {
    $instance = empty_app_key_import_fixture(false);
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'original']);

    [$operation, $socket] = native_env_import($instance, "APP_KEY=\n", false);

    expect($operation->status->value)->toBe('failed')->and($operation->error_code)->toBe('env.import_conflict')
        ->and(collect($socket->sent)->pluck('type')->last())->toBe('vault.delete');
    expect($instance->environmentValues()->sole()->env_value)->toBe('original');
});

it('preserves non-empty Laravel keys and does not initialize absent or non-Laravel keys', function (bool $laravel, string $contents, ?string $expected): void {
    $instance = empty_app_key_import_fixture(false);
    $instance->update(['source_is_laravel' => $laravel]);

    native_env_import($instance, $contents, false);

    expect($instance->environmentValues()->where('env_key', 'APP_KEY')->first()?->env_value)->toBe($expected);
})->with([
    'non-empty Laravel key' => [true, "APP_KEY=original\n", 'original'],
    'absent Laravel key' => [true, "OTHER=value\n", null],
    'empty non-Laravel key' => [false, "APP_KEY=\n", ''],
]);

function empty_app_key_import_fixture(bool $production): Instance
{
    $node = Node::query()->create([
        'name' => 'empty-key-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.201',
        'wireguard_ip' => '10.44.0.201',
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'role' => $production ? RoleName::AppProd : RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $project = Project::query()->create([
        'name' => 'Empty key',
        'slug' => 'empty-key',
        'repository_url' => 'https://example.test/empty-key.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $production ? 'e2e-prod' : 'e2e-dev',
        'environment' => $production ? 'production' : 'development',
        'checkout_path' => $production ? '/home/empty-key/releases/initial' : '/srv/orbit/empty-key/default',
        'production_home' => $production ? '/home/empty-key' : null,
        'production_user' => $production ? 'empty-key' : null,
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'empty-key.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => 'active']);

    return $instance;
}

/**
 * One terminal env:import run: Swamp read `$contents` into the vault; the projection reads it once and deletes it.
 *
 * @return array{0: Operation, 1: FakeRoutePublicationSocket}
 */
function native_env_import(Instance $instance, string $contents, bool $replace): array
{
    config(['swamp.address' => 'swamp.orbit', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'synthetic-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [$request['type'] === 'vault.read-secret'
        ? ['type' => 'vault.read-secret', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => 'orbit-control-inputs',
            'secretKey' => $request['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => $contents]]]
        : ['type' => 'vault.delete', 'id' => $request['id'], 'payload' => ['data' => ['deleted' => true]]]];
    app()->instance(WebSocketClient::class, $socket);
    $run = (string) Str::uuid();
    $operation = Operation::query()->create(['kind' => 'instance.env_import', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'input' => ['instance_id' => $instance->id, 'replace' => $replace,
            'direct_import' => ['node_id' => $instance->node_id, 'base' => '/unused', 'user' => 'orbit']],
        'state' => ['swamp_run_id' => $run]]);
    app(NativeInstanceEnvironmentImportProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded',
        'started_at' => now()->subSecond()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null,
        'result' => ['steps' => [], 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)]]);

    return [$operation->refresh(), $socket];
}

it('parses dotenv syntax and expands only preceding file-local values', function (): void {
    putenv('GATEWAY_FALLBACK=must-not-be-read');
    $_ENV['GATEWAY_FALLBACK'] = 'must-not-be-read';
    $contents = <<<'DOTENV'
        # comment
        BASE=alpha
        PLAIN=${BASE}-plain
        DOUBLE="line one\n${BASE}"
        SINGLE='literal ${BASE}'
        ESCAPED="quote: \""
        MULTILINE="first
        second"
        UNICODE=é
        UNICODE_EXPANDED="é${UNICODE}"

        DOTENV;

    $values = app(InstanceEnvironmentImporter::class)->parse($contents);

    expect($values)->toBe([
        'BASE' => 'alpha',
        'PLAIN' => 'alpha-plain',
        'DOUBLE' => "line one\nalpha",
        'SINGLE' => 'literal ${BASE}',
        'ESCAPED' => 'quote: "',
        'MULTILINE' => "first\nsecond",
        'UNICODE' => 'é',
        'UNICODE_EXPANDED' => 'éé',
    ]);
});

it('rejects duplicate unresolved invalid and oversized dotenv input atomically', function (string $contents): void {
    expect(fn () => app(InstanceEnvironmentImporter::class)->parse($contents))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.import_invalid');
        });
})->with([
    'duplicate' => ["KEY=one\nKEY=two\n"],
    'unresolved local expansion' => ['KEY=${MISSING}'],
    'gateway environment fallback' => ['KEY=${GATEWAY_FALLBACK}'],
    'invalid syntax' => ['KEY value'],
    'numeric key' => ['123=value'],
    'oversized' => [str_repeat('x', 1_048_577)],
]);

it('stores nothing when the vault copy differs from what the run read', function (): void {
    $instance = empty_app_key_import_fixture(false);
    config(['swamp.address' => 'swamp.orbit', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'synthetic-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [$request['type'] === 'vault.read-secret'
        ? ['type' => 'vault.read-secret', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => 'orbit-control-inputs',
            'secretKey' => $request['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => "CHANGED=1\n"]]]
        : ['type' => 'vault.delete', 'id' => $request['id'], 'payload' => ['data' => ['deleted' => true]]]];
    app()->instance(WebSocketClient::class, $socket);
    $run = (string) Str::uuid();
    $operation = Operation::query()->create(['kind' => 'instance.env_import', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'input' => ['instance_id' => $instance->id, 'replace' => true,
            'direct_import' => ['node_id' => $instance->node_id, 'base' => '/unused', 'user' => 'orbit']], 'state' => ['swamp_run_id' => $run]]);
    app(NativeInstanceEnvironmentImportProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'succeeded',
        'started_at' => now()->subSecond()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null,
        'result' => ['steps' => [], 'sha256' => hash('sha256', "ORIGINAL=1\n"), 'size' => 11]]);

    expect($operation->refresh()->error_code)->toBe('env.import_unconfirmed')
        ->and($instance->environmentValues()->count())->toBe(0)
        ->and(collect($socket->sent)->pluck('type')->last())->toBe('vault.delete');
});

it('maps a refused staging step to env.import_source_missing and deletes the vault key', function (): void {
    $instance = empty_app_key_import_fixture(false);
    config(['swamp.address' => 'swamp.orbit', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'synthetic-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'not_found']]];
    app()->instance(WebSocketClient::class, $socket);
    $run = (string) Str::uuid();
    $operation = Operation::query()->create(['kind' => 'instance.env_import', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'running', 'input' => ['instance_id' => $instance->id, 'replace' => true,
            'direct_import' => ['node_id' => $instance->node_id, 'base' => '/unused', 'user' => 'orbit']], 'state' => ['swamp_run_id' => $run]]);
    app(NativeInstanceEnvironmentImportProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed',
        'started_at' => now()->subSecond()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'operation.native_failed',
        'result' => ['steps' => ['Stage .env for the copy' => ['status' => 'failed', 'exit_code' => 3]]]]);

    expect($operation->refresh()->error_code)->toBe('env.import_source_missing')
        ->and(collect($socket->sent)->pluck('type')->all())->toBe(['vault.delete']);
});

it('stores the Laravel APP_URL as the Instance domain placeholder', function (): void {
    $instance = empty_app_key_import_fixture(false);

    native_env_import($instance, "APP_KEY=base64:synthetic\nAPP_URL=http://old.test\n", false);

    expect($instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)->toBe('base64:synthetic')
        ->and($instance->environmentValues()->where('env_key', 'APP_URL')->sole()->env_value)->toBe('https://{{instance.domain}}');
});
