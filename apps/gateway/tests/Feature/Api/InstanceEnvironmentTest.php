<?php

declare(strict_types=1);

use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentRouteDomain;
use App\Domain\Instances\Environment\InstanceEnvironmentRuntimeRefresher;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakesEnvironmentSyncInspection;

function environment_api_production(Instance $instance): void
{
    $instance->node->roles()->where('role', RoleName::AppDev)->delete();
    $instance->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance->update(['environment' => 'production', 'checkout_path' => '/home/orbit-env-test/releases/initial', 'production_user' => 'orbit-env-test', 'production_home' => '/home/orbit-env-test', 'selected_php_version' => '8.5']);
}

beforeEach(function (): void {
    [$this->caller, $this->instance, $this->route] = environment_api_fixture();
    $this->access = new EnvironmentApiAccess;
    app()->instance(InstanceOperationPreflight::class, $this->access);
    app()->instance(InstanceEnvironmentReader::class, $this->access);
    app()->instance(InstanceEnvironmentWriter::class, $this->access);
});

it('updates missing existing and identical values without contacting the workload Node', function (): void {
    app()->instance(SshExecutor::class, new class implements SshExecutor
    {
        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            throw new RuntimeException('Update contacted SSH.');
        }
    });
    $url = "/api/v1/instances/{$this->instance->id}/environment/FLAG";

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($url, ['value' => 'false'])
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'instance_id' => $this->instance->id,
                'operation' => 'update',
                'changed' => true,
                'key_count' => 1,
            ],
            'meta' => ['request_id' => request_id_from_test_response()],
        ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($url, ['value' => 'false'])
        ->assertJsonPath('data.changed', false);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($url, ['value' => '0'])
        ->assertJsonPath('data.changed', true);
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson($url, ['value' => ''])
        ->assertJsonPath('data.changed', true);

    expect(InstanceEnvironmentValue::query()->sole()->env_value)
        ->toBe('')
        ->and($this->access->preflights)
        ->toBe(0)
        ->and($this->access->reads)
        ->toBe(0)
        ->and(Activity::query()->latest('id')->value('command'))
        ->toBe('env:update');
});

it('imports by exact Route domain and applies conflict and replacement semantics', function (): void {
    $this->access->contents = "APP_KEY=synthetic-key\nNEW=value\n";
    $url = "/api/v1/instances/{$this->route->domain}/environment/import";

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.key_count', 2);

    $this->access->contents = "APP_KEY=other\nTHIRD=three\n";
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.import_conflict');

    expect($this->instance->environmentValues()->orderBy('env_key')->pluck('env_key')->all())
        ->toBe(['APP_KEY', 'NEW']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($url, ['replace' => true])
        ->assertOk()
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.key_count', 3);
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($url, ['replace' => true])
        ->assertJsonPath('data.changed', false);

    expect(Activity::query()->latest('id')->value('command'))->toBe('env:import');
});

it('imports and syncs the Laravel environment in its application directory', function (string $root, string $suffix): void {
    $this->instance->update(['root' => $root, 'source_is_laravel' => true]);
    $this->access->contents = "APP_KEY=nested-key\n";

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/import", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk();
    expect($this->access->readPaths)->toBe([$this->instance->checkout_path.$suffix]);
    expect($this->instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)->toBe('nested-key');

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk();
    expect($this->access->writePaths)->toBe([$this->instance->checkout_path.$suffix]);
})->with([
    'nested' => ['server/web/public', '/server/web'],
    'root public' => ['public', ''],
]);

it('normalizes Laravel APP_URL while preserving literal APP_KEY', function (): void {
    $this->instance->update(['source_is_laravel' => true]);
    $this->access->contents = "APP_KEY=base64:synthetic\nAPP_URL=http://old.test\n";

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/import",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertOk();

    expect($this->instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)
        ->toBe('base64:synthetic')
        ->and($this->instance->environmentValues()->where('env_key', 'APP_URL')->sole()->env_value)
        ->toBe('https://{{instance.domain}}');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/APP_URL", ['value' => 'https://wrong.test'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'env.configuration_invalid')
        ->assertJsonPath('error.details', [
            'key' => 'APP_URL',
            'rule' => 'laravel_app_url',
        ]);
});

it('returns redacted configuration details for invalid keys and unsupported placeholders', function (
    string $key,
    string $value,
    array $details,
): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson(
            '/api/v1/instances/'.$this->instance->id.'/environment/'.rawurlencode($key),
            ['value' => $value],
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'env.configuration_invalid')
        ->assertJsonPath('error.details', $details);

    expect($response->getContent())->not->toContain('environment-secret-sentinel');
})->with([
    'invalid key' => [
        'BAD KEY',
        'environment-secret-sentinel',
        ['key' => 'BAD KEY', 'rule' => 'key'],
    ],
    'unsupported placeholder' => [
        'KEY',
        'environment-secret-sentinel-{{instance.hostname}}',
        ['key' => 'KEY', 'rule' => 'placeholder', 'placeholder' => '{{instance.hostname}}'],
    ],
    'retired hostname placeholder' => [
        'KEY',
        'environment-secret-sentinel-{{app_instance.hostname}}',
        ['key' => 'KEY', 'rule' => 'placeholder', 'placeholder' => '{{app_instance.hostname}}'],
    ],
    'malformed placeholder' => [
        'KEY',
        'environment-secret-sentinel-{{app_instance.hostname}',
        ['key' => 'KEY', 'rule' => 'placeholder'],
    ],
]);

it('rejects malformed duplicate unknown and wrongly typed request members before mutation', function (
    string $method,
    string $suffix,
    string $body,
): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            $method,
            "/api/v1/instances/{$this->instance->id}/environment/{$suffix}",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(InstanceEnvironmentValue::query()->count())
        ->toBe(0)
        ->and($this->access->preflights)
        ->toBe(0)
        ->and($this->access->reads)
        ->toBe(0);
})->with([
    'import unknown' => ['POST', 'import', '{"node_id":1}'],
    'import duplicate' => ['POST', 'import', '{"replace":true,"replace":false}'],
    'import escaped duplicate' => ['POST', 'import', '{"replace":true,"repl\u0061ce":false}'],
    'import wrong type' => ['POST', 'import', '{"replace":"true"}'],
    'import malformed' => ['POST', 'import', '{"replace":'],
    'update unknown' => ['PUT', 'KEY', '{"value":"safe","path":"/tmp"}'],
    'update duplicate' => ['PUT', 'KEY', '{"value":"one","value":"two"}'],
    'update escaped duplicate' => ['PUT', 'KEY', '{"value":"one","\u0076alue":"two"}'],
    'update wrong type' => ['PUT', 'KEY', '{"value":false}'],
    'update malformed' => ['PUT', 'KEY', '{"value":'],
]);

it('returns 404 for an unknown selector and 409 for an ambiguous Route target', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson('/api/v1/instances/999999/environment/KEY', ['value' => 'value'])
        ->assertNotFound();

    [$domain] = ambiguous_environment_target($this->caller);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$domain}/environment/KEY", ['value' => 'value'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.target_ambiguous');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->route->domain}")
        ->assertNotFound();
});

it('enforces peer and owning-Node access before reading configuration or using SSH', function (): void {
    $denied = Node::query()->create([
        'name' => 'denied-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.202',
        'wireguard_ip' => '10.44.0.202',
        'user' => 'orbit',
    ]);
    DB::table('instance_environment_values')->insert([
        'instance_id' => $this->instance->id,
        'env_key' => 'BROKEN',
        'env_value' => 'not-ciphertext',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/KEY", ['value' => 'arbitrary-visible-value'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');
    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    expect($this->access->preflights)
        ->toBe(0)
        ->and($this->access->reads)
        ->toBe(0)
        ->and($this->access->writePreflights)
        ->toBe(0)
        ->and($this->access->writes)
        ->toBe([])
        ->and(InstanceEnvironmentValue::query()->count())
        ->toBe(1);
});

it('returns 409 instance.source_profile_missing for env operations without a recorded profile', function (
    string $method,
    string $suffix,
    string $body,
): void {
    $this->instance->update(['source_is_laravel' => null]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            $method,
            "/api/v1/instances/{$this->instance->id}/environment/{$suffix}",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_profile_missing')
        ->assertJsonPath(
            'error.message',
            'The Instance has no recorded source profile and cannot be used.',
        );

    expect(InstanceEnvironmentValue::query()->count())
        ->toBe(0)
        ->and($this->access->preflights)
        ->toBe(0)
        ->and($this->access->reads)
        ->toBe(0)
        ->and($this->access->writePreflights)
        ->toBe(0)
        ->and($this->access->writes)
        ->toBe([]);
})->with([
    'import' => ['POST', 'import', '{}'],
    'sync' => ['POST', 'sync', '{}'],
    'update' => ['PUT', 'FLAG', '{"value":"on"}'],
]);

it('requires an active complete owner while keeping stored updates offline', function (): void {
    $this->instance->node->update(['status' => LifecycleStatus::Failed]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/KEY", ['value' => 'offline'])
        ->assertOk();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/import",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.owner_unavailable');

    $this->instance->node->update(['status' => LifecycleStatus::Active]);
});

it('resolves a finished public Route while retaining its terminal replacement step', function (): void {
    environment_api_make_public($this->route);
    $this->route->update(['replacement_step' => RouteReplacementStep::IngressFirewall]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/KEY", ['value' => 'value'])
        ->assertOk();
});

it('returns 409 while a public Route is in an activation step', function (): void {
    environment_api_make_public($this->route);
    $this->route->update(['replacement_step' => RouteReplacementStep::IngressCaddy]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/KEY", ['value' => 'value'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.owner_unavailable');
});

it('returns 409 when a retained terminal step carries failed activation evidence', function (): void {
    environment_api_make_public($this->route);
    $this->route->update([
        'replacement_step' => RouteReplacementStep::IngressFirewall,
        'failed_step' => 'ingress-firewall',
        'error_code' => 'route.publication_failed',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->putJson("/api/v1/instances/{$this->instance->id}/environment/KEY", ['value' => 'value'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.owner_unavailable');
});

it('synchronizes a finished public Route while retaining its terminal replacement step', function (): void {
    $this->access->contents = '';
    environment_api_make_public($this->route);
    $this->route->update(['replacement_step' => RouteReplacementStep::IngressFirewall]);
    $this->instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'base64:stored-key']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertOk()
        ->assertJsonPath('data.operation', 'sync');
});

it('synchronizes by the existing selector with a narrow value-free result', function (): void {
    $this->access->contents = '';
    $this->instance
        ->environmentValues()
        ->createMany([
            ['env_key' => 'Z_LITERAL', 'env_value' => 'local-$VALUE'],
            ['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}'],
            ['env_key' => 'APP_KEY', 'env_value' => 'base64:stored-key'],
        ]);
    $url = "/api/v1/instances/{$this->route->domain}/environment/sync";

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'instance_id' => $this->instance->id,
                'operation' => 'sync',
                'changed' => true,
                'key_count' => 3,
            ],
            'meta' => ['request_id' => request_id_from_test_response()],
        ]);

    expect($this->access->writePreflights)
        ->toBe(1)
        ->and($this->access->requiredCapacities[0])
        ->toBeGreaterThan(1_048_576)
        ->and($this->access->writes)
        ->toBe([
            "APP_KEY=\"base64:stored-key\"\nAPP_URL=\"https://environment-api.test\"\nZ_LITERAL=\"local-\\\$VALUE\"\n",
        ]);

    $this->access->writeResult = InstanceEnvironmentWriteResult::unchanged();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()
        ->assertJsonPath('data.changed', false);

    expect(Activity::query()->latest('id')->value('command'))->toBe('env:sync');
});

it('keeps a release-layout production environment at the persistent home', function (): void {
    $this->access->contents = '';
    $home = '/home/orbit-app-216';
    $this->instance->node->roles()->where('role', RoleName::AppDev)->delete();
    $this->instance->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->instance->update([
        'environment' => 'production',
        'checkout_path' => "{$home}/releases/initial",
        'production_user' => 'orbit-app-216',
        'production_home' => $home,
        'root' => 'server/web/public',
        'source_is_laravel' => true,
    ]);
    $this->instance
        ->environmentValues()
        ->create([
            'env_key' => 'DB_DATABASE',
            'env_value' => '/srv/orb216/literal.sqlite',
        ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertOk();

    expect($this->access->paths)
        ->toBe([$home])
        ->and($this->access->writes)
        ->toBe(["DB_DATABASE=\"/srv/orb216/literal.sqlite\"\n"]);
});

it('refreshes only changed production environments and reports the outcome', function (bool $production, bool $changed): void {
    $this->access->contents = '';
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    $this->access->writeResult = $changed ? InstanceEnvironmentWriteResult::changed() : InstanceEnvironmentWriteResult::unchanged();
    if ($production) {
        $this->instance->node->roles()->where('role', RoleName::AppDev)->delete();
        $this->instance->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $this->instance->update([
            'environment' => 'production',
            'checkout_path' => '/home/orbit-env-test/releases/initial',
            'production_user' => 'orbit-env-test',
            'production_home' => '/home/orbit-env-test',
            'selected_php_version' => '8.5',
        ]);
    }
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    if ($production && $changed) {
        $refresh->shouldReceive('refresh')->once()->withArgs(fn (InstanceEnvironmentContext $context): bool => $context->instanceId === $this->instance->id)
            ->andReturn('config_cache_and_opcache_refreshed');
    } else {
        $refresh->shouldNotReceive('refresh');
    }
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()->assertJsonPath('data.changed', $changed);
    if ($production) {
        $response->assertJsonPath('data.runtime_refresh', $changed ? 'config_cache_and_opcache_refreshed' : 'skipped_unchanged');
    } else {
        $response->assertJsonMissingPath('data.runtime_refresh');
    }
})->with([[true, true], [true, false], [false, true], [false, false]]);

it('returns a safe refresh failure after the production environment was written', function (): void {
    $this->access->contents = '';
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    $this->instance->node->roles()->where('role', RoleName::AppDev)->delete();
    $this->instance->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->instance->update(['environment' => 'production', 'checkout_path' => '/home/orbit-env-test/releases/initial', 'production_user' => 'orbit-env-test', 'production_home' => '/home/orbit-env-test']);
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    $refresh->shouldReceive('refresh')->once()->andThrow(new ResourceOperationException(
        errorCode: 'env.php_runtime_refresh_failed',
        message: 'The environment file changed, but the production FPM opcache refresh failed.',
        status: 502,
    ));
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertStatus(502)->assertJsonPath('error.code', 'env.php_runtime_refresh_failed')
        ->assertJsonPath('error.message', 'The environment file changed, but the production FPM opcache refresh failed.');
    expect($this->access->writes)->toHaveCount(1);
});

it('does not refresh for production Route-domain synchronization', function (): void {
    environment_api_production($this->instance);
    $this->access->contents = '';
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    $refresh->shouldNotReceive('refresh');
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);

    $result = app(SynchronizeInstanceEnvironmentAction::class)->synchronizeRouteDomain(
        $this->instance, InstanceEnvironmentRouteDomain::Authoritative,
    );
    expect($result->changed)->toBeTrue()->and($result->runtimeRefresh)->toBeNull();
});

it('ignores changes only to the production testing environment file', function (): void {
    environment_api_production($this->instance);
    $this->access->contents = '';
    $this->access->writeResult = InstanceEnvironmentWriteResult::unchanged();
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    $connection = DatabaseConnection::query()->create([
        'slug' => 'env-testing', 'driver' => 'sqlite', 'path' => ':memory:',
        'owner_instance_id' => $this->instance->id, 'test_database' => ':memory:',
    ]);
    DatabaseConnectionTarget::query()->create([
        'instance_id' => $this->instance->id, 'database_connection_id' => $connection->id, 'prefix' => 'DB',
    ]);
    $writer = Mockery::mock(InstanceTestEnvironmentWriter::class);
    $writer->shouldReceive('mergeTesting')->once()->andReturn(InstanceEnvironmentWriteResult::changed());
    app()->instance(InstanceTestEnvironmentWriter::class, $writer);
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    $refresh->shouldNotReceive('refresh');
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()->assertJsonPath('data.changed', true)->assertJsonPath('data.runtime_refresh', 'skipped_unchanged');
});

it('keeps the production runtime refresh pending across cache clears when testing synchronization fails after replacing env', function (): void {
    environment_api_production($this->instance);
    $this->access->contents = '';
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    $connection = DatabaseConnection::query()->create([
        'slug' => 'env-testing', 'driver' => 'sqlite', 'path' => ':memory:',
        'owner_instance_id' => $this->instance->id, 'test_database' => ':memory:',
    ]);
    DatabaseConnectionTarget::query()->create([
        'instance_id' => $this->instance->id, 'database_connection_id' => $connection->id, 'prefix' => 'DB',
    ]);
    $writer = Mockery::mock(InstanceTestEnvironmentWriter::class);
    $writer->shouldReceive('mergeTesting')->once()->ordered()->andReturnUsing(function (): never {
        expect($this->access->contents)->toContain('QUEUE_CONNECTION="redis"')
            ->and($this->instance->refresh()->environment_runtime_pending_at)->not->toBeNull();

        throw new ResourceOperationException('env.testing_tracking_unknown', 'Git tracking could not be confirmed.', 409);
    });
    $writer->shouldReceive('mergeTesting')->times(3)->ordered()->andReturn(InstanceEnvironmentWriteResult::unchanged());
    app()->instance(InstanceTestEnvironmentWriter::class, $writer);
    $refreshed = false;
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    $refresh->shouldReceive('refresh')->once()->ordered()->andThrow(new ResourceOperationException('env.config_cache_refresh_failed', 'Old cache kept.', 502));
    $refresh->shouldReceive('refresh')->once()->ordered()->andReturnUsing(function () use (&$refreshed): string {
        $refreshed = true;

        return 'config_cache_and_opcache_refreshed';
    });
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);
    $process = Process::query()->create([
        'owner_type' => $this->instance->getMorphClass(), 'owner_id' => $this->instance->id,
        'name' => 'queue', 'runtime' => 'systemd', 'working_directory' => '/home/orbit-env-test/current',
        'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('restart')->once()->andReturnUsing(function (Process $restarted) use ($process, &$refreshed): void {
        expect($restarted->id)->toBe($process->id)
            ->and($refreshed)->toBeTrue()
            ->and($this->instance->refresh()->environment_runtime_pending_at)->not->toBeNull();
    });
    $runtime->shouldReceive('status')->once()->andReturn('running');
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $url = "/api/v1/instances/{$this->instance->id}/environment/sync";

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertConflict()->assertJsonPath('error.code', 'env.testing_tracking_unknown');
    expect($this->instance->refresh()->environment_runtime_pending_at)->not->toBeNull()->and($refreshed)->toBeFalse();
    cache()->flush();
    expect($this->instance->refresh()->environment_runtime_pending_at)->not->toBeNull();
    $written = $this->access->contents;
    $this->access->writeResult = InstanceEnvironmentWriteResult::unchanged();

    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertStatus(502)->assertJsonPath('error.code', 'env.config_cache_refresh_failed');
    expect($this->instance->refresh()->environment_runtime_pending_at)->not->toBeNull();

    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.runtime_refresh', 'config_cache_and_opcache_refreshed')
        ->assertJsonPath('data.restarted_process_ids', [$process->id]);
    expect($this->access->contents)->toBe($written)->and($this->instance->refresh()->environment_runtime_pending_at)->toBeNull();

    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()->assertJsonPath('data.runtime_refresh', 'skipped_unchanged')
        ->assertJsonPath('data.restarted_process_ids', []);
});

it('retries a pending refresh on an unchanged production sync', function (): void {
    environment_api_production($this->instance);
    $this->access->contents = '';
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    $refresh->shouldReceive('refresh')->once()->ordered()->andThrow(new ResourceOperationException('env.config_cache_refresh_failed', 'Old cache kept.', 502));
    $refresh->shouldReceive('refresh')->once()->ordered()->andReturn('config_cache_and_opcache_refreshed');
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);
    $url = "/api/v1/instances/{$this->instance->id}/environment/sync";
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')->assertStatus(502);
    $this->access->writeResult = InstanceEnvironmentWriteResult::unchanged();
    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.runtime_refresh', 'config_cache_and_opcache_refreshed');
    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.runtime_refresh', 'skipped_unchanged');
});

it('restarts only opted-in running Processes after env-sync refresh and reports failures safely', function (bool $fails): void {
    environment_api_production($this->instance);
    $this->access->contents = '';
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    $refreshed = false;
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    $refresh->shouldReceive('refresh')->once()->andReturnUsing(function () use (&$refreshed): string {
        $refreshed = true;

        return 'config_cache_and_opcache_refreshed';
    });
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);
    $attributes = [
        'owner_type' => $this->instance->getMorphClass(), 'owner_id' => $this->instance->id,
        'name' => 'queue', 'runtime' => 'systemd', 'working_directory' => '/home/orbit-env-test/current',
        'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ];
    $first = Process::query()->create($attributes);
    $second = Process::query()->create([...$attributes, 'name' => 'second']);
    foreach ([['name' => 'opt-out', 'restart_on_deploy' => false], ['name' => 'stopped', 'desired_state' => 'stopped'], ['name' => 'failed', 'status' => 'failed'], ['name' => 'other', 'owner_id' => $this->instance->id + 1]] as $override) {
        Process::query()->create([...$attributes, ...$override]);
    }
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('restart')->twice()->andReturnUsing(function (Process $process) use ($first, $second, $fails, &$refreshed): void {
        expect($refreshed)->toBeTrue()->and($process->id)->toBeIn([$first->id, $second->id]);
        if ($fails && $process->id === $second->id) {
            throw new RuntimeException('SECRET output');
        }
    });
    $runtime->shouldReceive('status')->times($fails ? 1 : 2)->andReturn('running');
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    if ($fails) {
        $response->assertStatus(502)->assertJsonPath('error.code', 'env.process_restart_failed')
            ->assertJsonPath('error.details.restarted_process_ids', (string) $first->id);
        expect($response->getContent())->not->toContain('SECRET');
        expect($this->instance->refresh()->environment_runtime_pending_at)->not->toBeNull();
    } else {
        $response->assertOk()->assertJsonPath('data.restarted_process_ids', [$first->id, $second->id]);
        expect($this->instance->refresh()->environment_runtime_pending_at)->toBeNull();
    }
})->with([false, true]);

it('requires explicit Process recovery after a real restart failure even if the next env-sync succeeds', function (): void {
    environment_api_production($this->instance);
    $this->access->contents = '';
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    $process = Process::query()->create([
        'owner_type' => $this->instance->getMorphClass(), 'owner_id' => $this->instance->id,
        'name' => 'queue', 'runtime' => 'systemd', 'working_directory' => '/home/orbit-env-test/current',
        'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    $refresh->shouldReceive('refresh')->twice()->andReturn('config_cache_and_opcache_refreshed');
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldReceive('restart')->once()->ordered()
        ->andThrow(new ProcessOperationException('restart', 'process.restart_failed', 'SECRET systemctl output'));
    $runtime->shouldReceive('restart')->once()->ordered()->andReturnNull();
    $runtime->shouldReceive('status')->twice()->andReturn('running');
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $url = "/api/v1/instances/{$this->instance->id}/environment/sync";

    $failure = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $failure->assertStatus(502)->assertJsonPath('error.code', 'env.process_restart_failed')
        ->assertJsonPath('error.details.restarted_process_ids', '');
    expect($failure->json('error.message'))->toContain('process:list', 'process:restart', 'env-sync skips failed Processes');
    expect($failure->getContent())->not->toContain('SECRET');
    $this->assertDatabaseHas('processes', ['id' => $process->id, 'status' => 'failed', 'error_code' => 'process.restart_failed']);

    $this->access->writeResult = InstanceEnvironmentWriteResult::unchanged();
    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.runtime_refresh', 'config_cache_and_opcache_refreshed')
        ->assertJsonPath('data.restarted_process_ids', []);
    $this->assertDatabaseHas('processes', ['id' => $process->id, 'status' => 'failed']);

    $this->postJson("/api/v1/processes/{$process->id}/restart")
        ->assertOk()->assertJsonPath('data.status', 'active');
    $this->assertDatabaseHas('processes', ['id' => $process->id, 'status' => 'active', 'error_code' => null]);
    $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk()->assertJsonPath('data.runtime_refresh', 'skipped_unchanged')
        ->assertJsonPath('data.restarted_process_ids', []);
});

it('does not restart Processes when production refresh is skipped or fails', function (string $outcome): void {
    environment_api_production($this->instance);
    $this->access->contents = '';
    $this->instance->environmentValues()->create(['env_key' => 'QUEUE_CONNECTION', 'env_value' => 'redis']);
    Process::query()->create([
        'owner_type' => $this->instance->getMorphClass(), 'owner_id' => $this->instance->id,
        'name' => 'queue', 'runtime' => 'systemd', 'working_directory' => '/home/orbit-env-test/current',
        'runtime_config' => ['command' => ['php', 'artisan', 'queue:work']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active',
    ]);
    $runtime = Mockery::mock(ProcessRuntimeManager::class);
    $runtime->shouldNotReceive('restart');
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $refresh = Mockery::mock(InstanceEnvironmentRuntimeRefresher::class);
    if (str_starts_with($outcome, 'env.')) {
        $refresh->shouldReceive('refresh')->once()->andThrow(new ResourceOperationException($outcome, 'Refresh failed safely.', 502));
    } else {
        $refresh->shouldReceive('refresh')->once()->andReturn($outcome);
    }
    app()->instance(InstanceEnvironmentRuntimeRefresher::class, $refresh);
    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    if (str_starts_with($outcome, 'env.')) {
        $response->assertStatus(502)->assertJsonPath('error.code', $outcome);
    } else {
        $response->assertOk()->assertJsonPath('data.runtime_refresh', $outcome)->assertJsonPath('data.restarted_process_ids', []);
    }
})->with(['skipped_no_php_version', 'skipped_no_current_release', 'env.config_cache_refresh_failed', 'env.php_runtime_refresh_failed']);

it('requires exactly an empty JSON object before synchronization work', function (string $body): void {
    $this->instance->environmentValues()->create(['env_key' => 'KEY', 'env_value' => 'value']);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($this->access->writePreflights)->toBe(0)->and($this->access->writes)->toBe([]);
})->with([
    'missing body' => '',
    'array' => '[]',
    'null' => 'null',
    'unknown member' => '{"replace":true}',
    'malformed' => '{',
]);

it('refuses missing configuration before remote work', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.configuration_missing');

    expect($this->access->writePreflights)->toBe(0)->and($this->access->writes)->toBe([]);
});

it('preflights from encrypted-size metadata before decrypting or writing', function (): void {
    DB::table('instance_environment_values')->insert([
        'instance_id' => $this->instance->id,
        'env_key' => 'BROKEN',
        'env_value' => 'not-ciphertext',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $url = "/api/v1/instances/{$this->instance->id}/environment/sync";
    $this->access->refuseWritePreflight = true;

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.write_preflight_failed');

    expect($this->access->writePreflights)->toBe(1)->and($this->access->writes)->toBe([]);

    $this->access->refuseWritePreflight = false;
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.configuration_unreadable');

    expect($this->access->writePreflights)->toBe(2)->and($this->access->writes)->toBe([]);
});

it('synchronizes through one inspection and retains the workload-key refusal', function (array $existing, bool $allowed): void {
    $this->instance->environmentValues()->create(['env_key' => 'KEY', 'env_value' => 'value']);
    $agents = new FakeAgentCommandRunner()->answer('instances.inspect-environment-sync', [
        'preflight' => ['state' => 'ok', 'installed' => false],
        'existing' => $existing,
    ]);
    app()->instance(InstanceOperationPreflight::class, new RemoteInstanceEnvironmentAccess($agents));

    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

    if ($allowed) {
        $response->assertOk();
        expect($this->access->writes)->toHaveCount(1);
    } else {
        $response->assertConflict()->assertJsonPath('error.code', 'env.sync_would_drop_keys');
        expect($this->access->writes)->toBe([]);
    }
    expect(array_column($agents->calls, 'command'))->toBe(['instances.inspect-environment-sync'])
        ->and($this->access->reads)->toBe(0)
        ->and($this->access->writePreflights)->toBe(0);
})->with([
    'missing file' => [['state' => 'missing', 'installed' => false], true],
    'stored key' => [['state' => 'ok', 'content' => base64_encode("KEY=old\n"), 'installed' => false], true],
    'unowned key' => [['state' => 'ok', 'content' => base64_encode("UNOWNED=keep\n"), 'installed' => false], false],
]);

it('reports an unconfirmed synchronization without values or an unchanged claim', function (): void {
    $this->access->contents = '';
    $sentinel = 'unconfirmed-secret-sentinel';
    $this->instance->environmentValues()->create(['env_key' => 'SECRET', 'env_value' => $sentinel]);
    $this->access->writeResult = InstanceEnvironmentWriteResult::unconfirmed();

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/environment/sync",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        )
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.sync_unconfirmed')
        ->assertJsonMissingPath('data.changed');

    expect($response->getContent())->not->toContain($sentinel);
});

it('refuses to drop workload keys that stored configuration lacks until they are imported', function (): void {
    // instance:create leaves a generated `.env` and no stored configuration.
    $this->access->contents = "APP_KEY=base64:generated\nAPP_URL=https://environment-api.test\nDB_DATABASE=app\nDB_HOST=127.0.0.1\n";
    $server = ['REMOTE_ADDR' => $this->caller->wireguard_ip];

    foreach (['FIRST' => 'one', 'SECOND' => 'two'] as $key => $value) {
        $this->withServerVariables($server)
            ->putJson("/api/v1/instances/{$this->instance->id}/environment/{$key}", ['value' => $value])
            ->assertOk();
    }

    $sync = fn () => $this->withServerVariables($server)->call(
        'POST',
        "/api/v1/instances/{$this->instance->id}/environment/sync",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    );

    $response = $sync()
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.sync_would_drop_keys')
        ->assertJsonPath('error.details.keys', 'APP_KEY,APP_URL,DB_DATABASE,DB_HOST');

    expect($response->json('error.message'))->toContain('env:import')
        ->and($response->getContent())->not->toContain('base64:generated')
        ->and($this->access->writes)->toBe([]);

    $this->withServerVariables($server)
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/import", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk();
    $sync()->assertOk()->assertJsonPath('data.key_count', 6);

    expect($this->access->writes)->toBe([
        "APP_KEY=\"base64:generated\"\nAPP_URL=\"https://environment-api.test\"\nDB_DATABASE=\"app\"\n"
            ."DB_HOST=\"127.0.0.1\"\nFIRST=\"one\"\nSECOND=\"two\"\n",
    ]);
});

it('removes keys Orbit wrote or detached but refuses a key added to the file by hand', function (): void {
    $this->instance->environmentValues()->createMany([
        ['env_key' => 'DB_HOST', 'env_value' => '127.0.0.1'],
        ['env_key' => 'KEEP', 'env_value' => 'kept'],
    ]);
    $this->access->contents = '';
    $url = "/api/v1/instances/{$this->instance->id}/environment/sync";
    $sync = fn () => $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

    $sync()->assertOk();
    expect($this->instance->refresh()->environment_owned_keys)->toBe(['DB_HOST', 'KEEP']);

    // A detach forgets its keys, and the next synchronization removes them from the file.
    $store = app(InstanceEnvironmentStore::class);
    $store->forget(app(InstanceEnvironmentContextResolver::class)->resolve($this->instance, false), ['DB_HOST'], 'detach');
    $sync()->assertOk();
    expect($this->access->contents)->toBe("KEEP=\"kept\"\n")
        ->and($this->instance->refresh()->environment_owned_keys)->toBe(['KEEP']);

    $this->access->contents .= "export MANUAL=edit\n";
    $sync()
        ->assertConflict()
        ->assertJsonPath('error.code', 'env.sync_would_drop_keys')
        ->assertJsonPath('error.details.keys', 'MANUAL');
});

it('synchronizes when the workload file does not exist', function (): void {
    $this->instance->environmentValues()->create(['env_key' => 'KEY', 'env_value' => 'value']);
    $this->access->contents = null;

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', "/api/v1/instances/{$this->instance->id}/environment/sync", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertOk();

    expect($this->access->writes)->toBe(["KEY=\"value\"\n"]);
});

function request_id_from_test_response(): string
{
    return request()->attributes->getString('orbit.request_id');
}

function environment_api_make_public(Route $route): void
{
    $cluster = Cluster::query()->create([
        'name' => 'environment-public-cluster',
        'state' => ClusterState::Active,
    ]);

    foreach ([RoleName::Router, RoleName::Ingress] as $role) {
        $node = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => "environment-{$role->value}",
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => $role === RoleName::Router ? '192.0.2.210' : '192.0.2.211',
            'wireguard_ip' => $role === RoleName::Router ? '10.44.0.210' : '10.44.0.211',
            'user' => 'orbit',
        ]);

        $node->roles()->create([
            'cluster_id' => $cluster->id,
            'role' => $role,
            'status' => LifecycleStatus::Active,
        ]);
    }

    $route->update([
        'node_id' => null,
        'cluster_id' => $cluster->id,
        'publication' => RoutePublication::Public,
    ]);
}

/** @return array{Node, Instance, Route} */
function environment_api_fixture(): array
{
    $caller = Node::query()->create([
        'name' => 'gateway-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.200',
        'wireguard_ip' => '10.44.0.200',
        'user' => 'orbit',
    ]);
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = Node::query()->create([
        'name' => 'environment-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.201',
        'wireguard_ip' => '10.44.0.201',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'Environment API',
        'slug' => 'environment-api',
        'repository_url' => 'https://example.test/environment-api.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/environment-api/default',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'environment-api.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => 'active']);

    return [$caller, $instance->fresh(['node']), $route];
}

/** @return array{string} */
function ambiguous_environment_target(Node $caller): array
{
    $cluster = Cluster::query()->create(['name' => 'environment-cluster', 'status' => 'active']);
    $project = Project::query()->create([
        'name' => 'Shared environment',
        'slug' => 'shared-environment',
        'repository_url' => 'https://example.test/shared-environment.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => 'shared-environment.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    foreach ([203, 204] as $suffix) {
        $node = Node::query()->create([
            'name' => "production-{$suffix}",
            'cluster_id' => $cluster->id,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => "192.0.2.{$suffix}",
            'wireguard_ip' => "10.44.0.{$suffix}",
            'user' => 'orbit',
        ]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => "production-{$suffix}",
            'environment' => 'production',
            'checkout_path' => "/home/app-{$suffix}",
            'production_home' => "/home/app-{$suffix}",
            'production_user' => "app-{$suffix}",
            'source_is_laravel' => false,
            'provisioning_step' => 'active',
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => $suffix - 203]);
    }

    $route->update(['status' => RouteStatus::Active]);
    Instance::query()->where('project_id', $project->id)->update(['status' => 'active']);

    return [$route->domain];
}

final class EnvironmentApiAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight
{
    use FakesEnvironmentSyncInspection;

    public int $preflights = 0;

    public int $reads = 0;

    public int $writePreflights = 0;

    /** @var list<int> */
    public array $requiredCapacities = [];

    /** @var list<string> */
    public array $writes = [];

    /** @var list<string> */
    public array $paths = [];

    /** @var list<string> */
    public array $readPaths = [];

    /** @var list<string> */
    public array $writePaths = [];

    public bool $refuseWritePreflight = false;

    public InstanceEnvironmentWriteResult $writeResult;

    /** The workload file, or null when it does not exist. */
    public ?string $contents = "KEY=value\n";

    public function __construct()
    {
        $this->writeResult = InstanceEnvironmentWriteResult::changed();
    }

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        $this->preflights++;
    }

    public function assertEnvironmentWritable(
        InstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void {
        $this->writePreflights++;
        $this->requiredCapacities[] = $requiredCapacityBytes;
        $this->paths[] = $context->path;

        if ($this->refuseWritePreflight) {
            throw new ResourceOperationException(
                errorCode: 'env.write_preflight_failed',
                message: 'The recorded Instance environment file cannot be replaced safely.',
                status: 409,
            );
        }
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        $this->reads++;
        $this->readPaths[] = $context->path;

        if ($this->contents === null) {
            throw new ResourceOperationException(
                errorCode: 'env.import_source_missing',
                message: 'The recorded Instance environment file does not exist.',
                status: 404,
            );
        }

        return $this->contents;
    }

    public function write(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): InstanceEnvironmentWriteResult {
        $this->writes[] = $contents;
        $this->writePaths[] = $context->path;
        $this->contents = $contents;

        return $this->writeResult;
    }
}
