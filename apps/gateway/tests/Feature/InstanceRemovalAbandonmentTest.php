<?php

declare(strict_types=1);

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Data\Instances\InstanceData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    Http::preventStrayRequests();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'restore-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Restore fixture', 'slug' => 'restore-fixture', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/fixture.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'fixture',
        'checkout_path' => '/home/orbit/apps/fixture', 'status' => 'active', 'source_layout' => 'checkout', 'root' => 'public',
        'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $this->route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'fixture.restore.test', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->destroy = app(StartInstanceDestroyAction::class)->execute($this->instance, true);
    $this->instance->refresh();
    $this->removal = InstanceRemoval::query()->findOrFail($this->destroy->input['removal_id']);
    $this->removal->update(['status' => InstanceRemovalStatus::Failed, 'current_step' => InstanceRemovalStep::RuntimeCleanup,
        'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'fixture.drop_blocked']);
    $this->destroy->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'fixture.drop_blocked',
        'state' => ['swamp_run_id' => 'fixture-run', 'native_progress' => ['steps' => [['name' => 'Remove the source', 'status' => 'skipped']]]]]);
    app(RoutePublicationHolds::class)->release($this->destroy->id);
    $this->mock(RemoteProgramRunner::class)->shouldReceive('run')->andReturn(new ProgramResult('inspected', ['dependents' => []], ''));
});

it('preserves history and permits a fresh removal after abandonment without deletion receipts', function (): void {
    $old = $this->removal->id;
    DB::transaction(function (): void {
        $this->removal->members()->update(['abandoned_at' => now()]);
        $this->removal->update(['status' => InstanceRemovalStatus::Abandoned, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
        $this->instance->update(['status' => InstanceState::Active]);
        $this->route->refresh()->update(['status' => 'active', 'sites_published' => true]);
    });
    expect($this->instance->fresh()->removalMember)->toBeNull()
        ->and(InstanceRemovalMember::query()->live()->count())->toBe(0)
        ->and($this->removal->members()->sole()->row_deleted_at)->toBeNull()
        ->and($this->destroy->fresh()->status->value)->toBe('failed');
    $next = app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true);
    expect($next->input['removal_id'])->not->toBe($old)
        ->and(InstanceRemoval::query()->count())->toBe(2)
        ->and(InstanceRemovalMember::query()->live()->count())->toBe(1)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Abandoned);
});

it('rejects reactivation while live membership still owns the Instance', function (): void {
    expect(fn () => $this->instance->update(['status' => InstanceState::Active]))->toThrow(QueryException::class);
});

it('rejects abandonment without every retained member', function (): void {
    expect(fn () => $this->removal->update(['status' => InstanceRemovalStatus::Abandoned, 'current_step' => null,
        'failed_step' => null, 'error_code' => null]))->toThrow(QueryException::class);
});

it('excludes abandoned history from JSON and the unavailable removal site', function (): void {
    expect(InstanceData::fromModel($this->instance->fresh())->removal)->not->toBeNull();
    $this->removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);
    $this->removal->members()->update(['source_prepared_at' => now()]);
    $this->removal->update(['status' => InstanceRemovalStatus::Failed, 'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'fixture.failed']);
    $this->route->targets()->delete();
    $this->route->refresh()->update(['sites_published' => true]);
    expect(app(DevelopmentSiteRepository::class)->forNode($this->instance->node)->where('domain', $this->route->domain))->not->toBeEmpty();
    DB::transaction(function (): void {
        $this->removal->members()->update(['abandoned_at' => now()]);
        $this->removal->update(['status' => InstanceRemovalStatus::Abandoned, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
    });
    expect(InstanceData::fromModel($this->instance->fresh())->removal)->toBeNull()
        ->and($this->instance->fresh()->removalMember)->toBeNull()
        ->and(app(DevelopmentSiteRepository::class)->forNode($this->instance->node)->where('domain', $this->route->domain))->toBeEmpty();
});

it('permits marking members abandoned only while their removal is failed', function (): void {
    $this->removal->update(['status' => InstanceRemovalStatus::Removing, 'current_step' => InstanceRemovalStep::RuntimeCleanup, 'failed_step' => null, 'error_code' => null]);
    expect(fn () => $this->removal->members()->update(['abandoned_at' => now()]))->toThrow(QueryException::class);
    expect(InstanceRemovalMember::query()->live()->count())->toBe(1);
    $this->removal->update(['status' => InstanceRemovalStatus::Failed, 'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'fixture.failed']);
    $this->removal->members()->update(['abandoned_at' => now()]);
    expect(fn () => $this->removal->update(['status' => InstanceRemovalStatus::Abandoned]))->toThrow(QueryException::class);
    $this->removal->update(['status' => InstanceRemovalStatus::Abandoned, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
    expect(fn () => $this->removal->members()->update(['abandoned_at' => null]))->toThrow(QueryException::class);
    expect(fn () => $this->removal->update(['status' => InstanceRemovalStatus::Failed, 'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'fixture.failed']))->toThrow(QueryException::class);
});

it('keeps normal removing and failed retries on their original removal and requires deletion for completion', function (): void {
    $old = $this->removal->id;
    $retry = app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true);
    expect($retry->input['removal_id'])->toBe($old)
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and(InstanceRemovalMember::query()->live()->count())->toBe(1);
    expect(fn () => $this->removal->refresh()->update(['status' => InstanceRemovalStatus::Completed, 'current_step' => null, 'failed_step' => null, 'error_code' => null]))->toThrow(QueryException::class);
});

it('migrates populated prior schema without rewriting rows or losing triggers', function (): void {
    config(['database.connections.abandonment_migration' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);
    $seed = [];
    foreach (['nodes', 'node_roles', 'projects', 'instances', 'routes', 'route_targets', 'instance_removals', 'instance_removal_members', 'operations'] as $table) {
        $seed[$table] = DB::table($table)->get()->map(function ($row) {
            $row = (array) $row;
            unset($row['abandoned_at']);

            return $row;
        })->all();
    }
    $migrator = app('migrator');
    try {
        $migrator->usingConnection('abandonment_migration', function () use ($migrator, $seed): void {
            $migrator->getRepository()->createRepository();
            $new = database_path('migrations/2027_02_07_000000_allow_abandoned_instance_removals.php');
            $files = array_values(array_diff(glob(database_path('migrations/*.php')), [$new]));
            $migrator->run($files);
            $savedTriggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger'");
            DB::statement('PRAGMA foreign_keys = OFF');
            foreach ($savedTriggers as $trigger) {
                DB::unprepared('DROP TRIGGER "'.str_replace('"', '""', $trigger->name).'"');
            }
            foreach ($seed as $table => $rows) {
                if ($rows !== []) {
                    DB::table($table)->insert($rows);
                }
            }
            foreach ($savedTriggers as $trigger) {
                DB::unprepared($trigger->sql);
            }
            DB::statement('PRAGMA foreign_keys = ON');
            $tables = ['instances', 'instance_removals', 'instance_removal_members', 'operations'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            $triggers = DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('name')->all();
            $migration = require $new;
            $migration->up();
            foreach ($tables as $table) {
                $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table) {
                    $row = (array) $row;
                    if ($table === 'instance_removal_members') {
                        expect($row['abandoned_at'])->toBeNull();
                        unset($row['abandoned_at']);
                    }

                    return $row;
                })->all();
                expect($after)->toBe($before[$table]);
            }
            expect(DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('name')->all())->toBe($triggers)
                ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
            expect(fn () => $migration->down())->toThrow(LogicException::class, 'forward-only');
        });
    } finally {
        DB::purge('abandonment_migration');
    }
});

it('keeps completed removals terminal with real deletion receipts', function (): void {
    $completed = app(ProjectDirectInstanceDestroyAction::class)->execute($this->destroy->input, ['removed' => true]);
    expect($completed->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($completed->members->sole()->row_deleted_at)->not->toBeNull()
        ->and($completed->members->sole()->abandoned_at)->toBeNull()
        ->and(InstanceRemovalMember::query()->live()->count())->toBe(0)
        ->and(Instance::query()->find($this->instance->id))->toBeNull();
    expect(fn () => $completed->update(['status' => InstanceRemovalStatus::Failed, 'current_step' => InstanceRemovalStep::RuntimeCleanup, 'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'fixture.failed']))->toThrow(QueryException::class);
});
