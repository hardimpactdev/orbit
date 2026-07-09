<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\AppInstanceDatabaseConnectionTarget;
use App\Models\AppInstanceEnvVariable;
use App\Models\AppInstanceRuntimeMount;
use App\Models\AppRuntimeMount;
use App\Models\AppSetupStep;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seed_happie_nmbp_repair_fixture(): array
{
    $beast = Node::factory()->appDev(['tld' => 'beast'])->create(['name' => 'Beast']);
    $nmbp = Node::factory()->appDev(['tld' => 'nmbp'])->create(['name' => 'NMBP']);

    $happie = App::factory()->for($beast, 'node')->create([
        'name' => 'happie',
        'path' => '/Users/nckrtl/apps/happie-beast',
        'domain' => null,
        'environment' => 'development',
    ]);

    $happieNmbp = App::factory()->for($nmbp, 'node')->create([
        'name' => 'happie-nmbp',
        'path' => '/Users/nckrtl/apps/happie',
        'domain' => 'happie.nmbp',
        'environment' => 'development',
    ]);

    $happieNmbpInstance = AppInstance::factory()->for($happieNmbp)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $nmbp->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);

    AppInstanceEnvVariable::query()->create([
        'app_instance_id' => $happieNmbpInstance->id,
        'key' => 'APP_URL',
        'value' => 'https://happie.nmbp',
        'secret' => false,
    ]);

    $workspace = Workspace::factory()->create([
        'app_id' => $happieNmbp->id,
        'name' => 'recipe',
        'path' => '/Users/nckrtl/apps/happie/workspaces/recipe',
    ]);

    $proxyRoute = ProxyRoute::factory()->create([
        'node_id' => $nmbp->id,
        'domain' => 'recipe.happie-nmbp.nmbp',
        'app_id' => $happieNmbp->id,
        'workspace_id' => $workspace->id,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
    ]);

    $appProcess = Process::factory()
        ->forOwner($happieNmbp, $nmbp)
        ->create([
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => ProcessRestartPolicy::Always,
            'crash_notification' => ProcessCrashNotification::None,
            'runtime' => ProcessRuntime::Systemd,
        ]);

    $workspaceProcess = Process::factory()
        ->forOwner($workspace, $nmbp)
        ->create([
            'name' => 'vite',
            'command' => 'npm run dev',
        ]);

    WorkspaceStep::factory()->create([
        'app_id' => $happieNmbp->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install',
    ]);

    AppSetupStep::factory()->create([
        'app_id' => $happieNmbp->id,
        'sort_order' => 1,
        'command' => 'php artisan migrate',
    ]);

    $databaseConnectionTarget = DatabaseConnectionTarget::factory()
        ->forApp($happieNmbp)
        ->create(['env_prefix' => 'DB']);

    AppInstanceDatabaseConnectionTarget::query()->create([
        'database_connection_id' => $databaseConnectionTarget->database_connection_id,
        'app_instance_id' => $happieNmbpInstance->id,
        'env_prefix' => 'INSTANCE_DB',
    ]);

    AppRuntimeMount::query()->create([
        'app_id' => $happieNmbp->id,
        'source' => '/Users/nckrtl/apps/happie/storage',
        'target' => '/app/storage',
        'read_only' => false,
    ]);

    return compact(
        'beast',
        'nmbp',
        'happie',
        'happieNmbp',
        'happieNmbpInstance',
        'workspace',
        'proxyRoute',
        'appProcess',
        'workspaceProcess',
        'databaseConnectionTarget',
    );
}

function seed_happie_canonical_legacy_repair_fixture(): array
{
    $beast = Node::factory()->appDev(['tld' => 'beast'])->create(['name' => 'Beast']);
    $nmbp = Node::factory()->appDev(['tld' => 'nmbp'])->create(['name' => 'NMBP']);

    $happie = App::factory()->for($beast, 'node')->create([
        'name' => 'happie',
        'path' => '/Users/nckrtl/apps/happie-beast',
        'domain' => null,
        'environment' => 'development',
    ]);

    $happieDevelopmentInstance = AppInstance::factory()->for($happie)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $beast->id,
            node: 'Beast',
            path: '/Users/nckrtl/apps/happie-beast',
            document_root: 'public',
            domain: 'happie.beast',
        ),
    ]);

    $happieNmbpInstance = AppInstance::factory()->for($happie)->create([
        'name' => 'nmbp',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $nmbp->id,
            node: 'NMBP',
            path: '/Users/nckrtl/apps/happie',
            document_root: 'public',
            domain: 'happie.nmbp',
        ),
    ]);

    WorkspaceStep::factory()->create([
        'app_id' => $happie->id,
        'app_instance_id' => null,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'sort_order' => 1,
        'command' => 'composer install',
    ]);

    AppRuntimeMount::query()->create([
        'app_id' => $happie->id,
        'source' => '/Users/nckrtl/apps/happie/storage',
        'target' => '/app/storage',
        'read_only' => false,
    ]);

    return compact('beast', 'nmbp', 'happie', 'happieDevelopmentInstance', 'happieNmbpInstance');
}

function expect_happie_nmbp_workspace_steps_to_be_instance_scoped(App $happie, AppInstance $instance): void
{
    expect(WorkspaceStep::query()->where('app_id', $happie->id)->where('app_instance_id', $instance->id)->count())
        ->toBe(1)
        ->and(WorkspaceStep::query()->where('app_id', $happie->id)->whereNull('app_instance_id')->exists())
        ->toBeFalse();
}

function expect_happie_nmbp_runtime_mounts_to_be_instance_scoped(App $happie, AppInstance $instance): void
{
    expect(AppRuntimeMount::query()->where('app_id', $happie->id)->where('target', '/app/storage')->exists())
        ->toBeFalse()
        ->and(
            AppInstanceRuntimeMount::query()
                ->where('app_instance_id', $instance->id)
                ->where('source', '/Users/nckrtl/apps/happie/storage')
                ->where('target', '/app/storage')
                ->where('read_only', false)
                ->exists(),
        )
        ->toBeTrue();
}

/**
 * @mago-expect lint:halstead
 */
describe('orbit:internal:repair-happie-nmbp-instance', function (): void {
    it('refuses to mutate registry state without --execute', function (): void {
        seed_happie_nmbp_repair_fixture();

        $this->artisan('orbit:internal:repair-happie-nmbp-instance')->assertSuccessful();

        expect(App::query()->where('name', 'happie-nmbp')->exists())->toBeTrue();
    });

    it('consolidates the known happie-nmbp workaround into a canonical happie NMBP instance', function (): void {
        seed_happie_nmbp_repair_fixture();

        $this->artisan('orbit:internal:repair-happie-nmbp-instance', ['--execute' => true])->assertSuccessful();

        $happie = App::query()->where('name', 'happie')->firstOrFail();
        $workspace = Workspace::query()->where('name', 'recipe')->firstOrFail();
        $instance = AppInstance::query()->where('app_id', $happie->id)->where('name', 'nmbp')->first();

        expect(App::query()->where('name', 'happie-nmbp')->exists())
            ->toBeFalse()
            ->and($workspace->app_id)
            ->toBe($happie->id)
            ->and($instance)
            ->toBeInstanceOf(AppInstance::class)
            ->and($instance->driver)
            ->toBe(AppInstanceDriver::Orbit)
            ->and($instance->driver_config)
            ->toBeInstanceOf(OrbitAppInstanceDriverConfigData::class)
            ->and($instance->driver_config->node)
            ->toBe('NMBP')
            ->and($instance->driver_config->path)
            ->toBe('/Users/nckrtl/apps/happie')
            ->and($instance->driver_config->domain)
            ->toBe('happie.nmbp')
            ->and($instance->driver_config->document_root)
            ->toBe('public')
            ->and(Process::query()->where('name', 'queue')->firstOrFail()->owner_id)
            ->toBe($happie->id)
            ->and(Process::query()->where('name', 'vite')->firstOrFail()->owner_id)
            ->toBe($workspace->id)
            ->and($workspace->app_instance_id)
            ->toBe($instance->id)
            ->and(AppSetupStep::query()->where('app_id', $happie->id)->count())
            ->toBe(1)
            ->and(DatabaseConnectionTarget::query()->where('app_id', $happie->id)->where('env_prefix', 'DB')->exists())
            ->toBeTrue()
            ->and(
                AppInstanceEnvVariable::query()
                    ->where('app_instance_id', $instance->id)
                    ->where('key', 'APP_URL')
                    ->exists(),
            )
            ->toBeTrue()
            ->and(
                AppInstanceDatabaseConnectionTarget::query()
                    ->where('app_instance_id', $instance->id)
                    ->where('env_prefix', 'INSTANCE_DB')
                    ->exists(),
            )
            ->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'recipe.happie.nmbp')->exists())
            ->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'recipe.happie-nmbp.nmbp')->exists())
            ->toBeFalse();

        expect($workspace->url())->toBe('https://recipe.happie.nmbp');
        expect_happie_nmbp_workspace_steps_to_be_instance_scoped($happie, $instance);
        expect_happie_nmbp_runtime_mounts_to_be_instance_scoped($happie, $instance);
    });

    it('repairs canonical happie legacy setup rows after the workaround app has already been removed', function (): void {
        $fixture = seed_happie_canonical_legacy_repair_fixture();

        $this->artisan('orbit:internal:repair-happie-nmbp-instance', ['--execute' => true])->assertSuccessful();

        expect(App::query()->where('name', 'happie-nmbp')->exists())
            ->toBeFalse()
            ->and(
                WorkspaceStep::query()
                    ->where('app_id', $fixture['happie']->id)
                    ->where('app_instance_id', $fixture['happieNmbpInstance']->id)
                    ->where('phase', WorkspaceLifecyclePhase::Setup)
                    ->pluck('command')
                    ->all(),
            )
            ->toBe(['composer install'])
            ->and(
                WorkspaceStep::query()
                    ->where('app_id', $fixture['happie']->id)
                    ->where('app_instance_id', $fixture['happieDevelopmentInstance']->id)
                    ->exists(),
            )
            ->toBeFalse();

        expect_happie_nmbp_workspace_steps_to_be_instance_scoped($fixture['happie'], $fixture['happieNmbpInstance']);
        expect_happie_nmbp_runtime_mounts_to_be_instance_scoped($fixture['happie'], $fixture['happieNmbpInstance']);
    });

    it('refuses when canonical happie already owns a conflicting workspace name', function (): void {
        $fixture = seed_happie_nmbp_repair_fixture();

        Workspace::factory()->create([
            'app_id' => $fixture['happie']->id,
            'name' => 'recipe',
        ]);

        $this->artisan('orbit:internal:repair-happie-nmbp-instance', ['--execute' => true])->assertFailed();

        expect(App::query()->where('name', 'happie-nmbp')->exists())->toBeTrue();
    });

    it('moves a workaround-owned proxy route that already has the canonical domain', function (): void {
        seed_happie_nmbp_repair_fixture();

        ProxyRoute::query()->where('domain', 'recipe.happie-nmbp.nmbp')->update(['domain' => 'recipe.happie.nmbp']);

        $this->artisan('orbit:internal:repair-happie-nmbp-instance', ['--execute' => true])->assertSuccessful();

        $happie = App::query()->where('name', 'happie')->firstOrFail();

        expect(
            ProxyRoute::query()->where('domain', 'recipe.happie.nmbp')->where('app_id', $happie->id)->exists(),
        )->toBeTrue();
    });

    it('refuses when another app already owns the repaired proxy route domain', function (): void {
        $fixture = seed_happie_nmbp_repair_fixture();

        ProxyRoute::factory()->create([
            'node_id' => $fixture['nmbp']->id,
            'domain' => 'recipe.happie.nmbp',
            'app_id' => $fixture['happie']->id,
            'workspace_id' => null,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $this->artisan('orbit:internal:repair-happie-nmbp-instance', ['--execute' => true])->assertFailed();

        expect(App::query()->where('name', 'happie-nmbp')->exists())->toBeTrue();
    });

    it('refuses when the canonical NMBP instance already owns conflicting instance state', function (): void {
        $fixture = seed_happie_nmbp_repair_fixture();

        $canonicalInstance = AppInstance::factory()->for($fixture['happie'])->create([
            'name' => 'nmbp',
        ]);

        AppInstanceEnvVariable::query()->create([
            'app_instance_id' => $canonicalInstance->id,
            'key' => 'APP_URL',
            'value' => 'https://happie.nmbp',
            'secret' => false,
        ]);

        $this->artisan('orbit:internal:repair-happie-nmbp-instance', ['--execute' => true])->assertFailed();

        expect(App::query()->where('name', 'happie-nmbp')->exists())->toBeTrue();
    });

    it('refuses when the canonical NMBP instance already owns a conflicting runtime mount target', function (): void {
        $fixture = seed_happie_nmbp_repair_fixture();

        $canonicalInstance = AppInstance::factory()->for($fixture['happie'])->create([
            'name' => 'nmbp',
        ]);

        AppInstanceRuntimeMount::query()->create([
            'app_instance_id' => $canonicalInstance->id,
            'source' => '/Users/nckrtl/apps/happie/shared-storage',
            'target' => '/app/storage',
            'read_only' => false,
        ]);

        $this->artisan('orbit:internal:repair-happie-nmbp-instance', ['--execute' => true])->assertFailed();

        expect(App::query()->where('name', 'happie-nmbp')->exists())->toBeTrue();
    });
});
