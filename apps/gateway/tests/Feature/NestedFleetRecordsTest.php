<?php

declare(strict_types=1);

use App\Actions\Instances\RegisterInstanceAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceCloneAction;
use App\Actions\Instances\StartInstanceMigrateAction;
use App\Data\Instances\CloneInstanceData;
use App\Data\Instances\CreateInstanceData;
use App\Data\Instances\RegisterInstanceData;
use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Instances\InstanceBranchSelection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/Domain/Instances/ContainerInstanceHelpers.php';
beforeEach(function (): void {
    Queue::fake();
    $this->withoutMiddleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class]);
});

describe('nested-fleet Project records', function (): void {
    it('defaults off and updates through the API without source reads or Operations', function (): void {
        $project = containerDefaultProject();
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->getJson('/api/v1/projects/'.$project->id)->assertOk()->assertJsonPath('data.nested_fleet', false);
        config(['instances.nested_fleet_projects' => [$project->id]]);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertOk()->assertJsonPath('data.nested_fleet', true);
        $this->patchJson('/api/v1/projects/'.$project->id, ['name' => 'New name'])->assertOk()->assertJsonPath('data.nested_fleet', true);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => false])->assertOk()->assertJsonPath('data.nested_fleet', false);
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->assertDatabaseCount('operations', 0);
    });
    it('refuses enabling a Project outside the operator trial allow list', function (string $mode): void {
        $project = containerDefaultProject();
        if ($mode === 'other Project') {
            config(['instances.nested_fleet_projects' => [$project->id + 1]]);
        }
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'project.nested_fleet_not_allowed');
        expect($project->refresh()->nested_fleet)->toBeFalse()->and($project->name)->toBe('tool');
        $this->assertDatabaseCount('operations', 0);
        Queue::assertNothingPushed();
    })->with(['default empty', 'other Project']);

    it('refuses a flag change that travels with another Project field, before any write', function (array $other): void {
        $project = containerDefaultProject();
        config(['instances.nested_fleet_projects' => [$project->id]]);
        $before = $project->refresh()->getRawOriginal();
        $this->patchJson('/api/v1/projects/'.$project->id, [...$other, 'nested_fleet' => true])
            ->assertStatus(422)->assertJsonPath('error.code', 'project.nested_fleet_update_separate');
        expect($project->refresh()->getRawOriginal())->toBe($before);
        $this->assertDatabaseCount('operations', 0);
    })->with([
        'code' => [['code' => 'NEW']],
        'name' => [['name' => 'Other']],
        'type' => [['type' => 'laravel-package']],
        'source access' => [['source_access' => 'gh_cli']],
        'task check' => [['task_check' => 'composer test']],
        'root' => [['root' => 'public']],
    ]);

    it('refuses an invalid operator trial allow list', function (mixed $list): void {
        $project = containerDefaultProject();
        config(['instances.nested_fleet_projects' => $list]);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])
            ->assertStatus(500)->assertJsonPath('error.code', 'project.nested_fleet_projects_invalid');
        expect($project->refresh()->nested_fleet)->toBeFalse();
        Queue::assertNothingPushed();
    })->with(['malformed JSON' => [null], 'object' => [['id' => 1]], 'string ID' => [['1']], 'zero ID' => [[0]]]);

    it('reads the operator trial allow list from its raw environment value as a JSON list only', function (string $raw, int $status, string $code): void {
        $project = containerDefaultProject();
        $raw = str_replace('ID', (string) $project->id, $raw);
        $_SERVER['ORBIT_INSTANCES_NESTED_FLEET_PROJECTS'] = $_ENV['ORBIT_INSTANCES_NESTED_FLEET_PROJECTS'] = $raw;
        try {
            config(['instances.nested_fleet_projects' => (require config_path('instances.php'))['nested_fleet_projects']]);
        } finally {
            unset($_SERVER['ORBIT_INSTANCES_NESTED_FLEET_PROJECTS'], $_ENV['ORBIT_INSTANCES_NESTED_FLEET_PROJECTS']);
        }
        $response = $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertStatus($status);
        if ($status === 200) {
            expect($project->refresh()->nested_fleet)->toBeTrue();

            return;
        }
        $response->assertJsonPath('error.code', $code);
        expect($project->refresh()->nested_fleet)->toBeFalse();
    })->with([
        'list' => ['[ID]', 200, ''],
        'unset' => ['', 409, 'project.nested_fleet_not_allowed'],
        'empty list' => ['[]', 409, 'project.nested_fleet_not_allowed'],
        'object with a list key' => ['{"0":ID}', 500, 'project.nested_fleet_projects_invalid'],
        'empty object' => ['{}', 500, 'project.nested_fleet_projects_invalid'],
        'malformed JSON' => ['[ID', 500, 'project.nested_fleet_projects_invalid'],
        'string ID' => ['["ID"]', 500, 'project.nested_fleet_projects_invalid'],
        'zero ID' => ['[0,ID]', 500, 'project.nested_fleet_projects_invalid'],
        'negative ID' => ['[-1,ID]', 500, 'project.nested_fleet_projects_invalid'],
        'nested object' => ['[{"id":ID}]', 500, 'project.nested_fleet_projects_invalid'],
    ]);

    it('allows an unchanged flag or clearing it after removal from the trial allow list', function (): void {
        $project = containerDefaultProject();
        config(['instances.nested_fleet_projects' => [$project->id]]);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertOk();
        config(['instances.nested_fleet_projects' => []]);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertOk()->assertJsonPath('data.nested_fleet', true);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => false])->assertOk()->assertJsonPath('data.nested_fleet', false);
        expect($project->refresh()->nested_fleet)->toBeFalse();
        Queue::assertNothingPushed();
    });
    it('requires a JSON boolean', function (mixed $value): void {
        $project = containerDefaultProject();
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => $value])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['details' => ['nested_fleet']]]);
        expect($project->refresh()->nested_fleet)->toBeFalse();
    })->with(['text' => ['true'], 'integer' => [1], 'null' => [null]]);
    it('refuses a flag change once the Project has any Instance', function (bool $container): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        config(['instances.nested_fleet_projects' => [$project->id]]);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        if ($container) {
            app(InstanceContainer::class)->reserve($instance, $node);
        }
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertStatus(409)->assertJsonPath('error.code', 'project.nested_fleet_in_use');
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => false])->assertOk();
    })->with([false, true]);
});

it('upgrades existing Projects with nested fleet off and rolls back without losing their data', function (): void {
    $previous = config('database.default');
    config(['database.connections.nested_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
        'database.default' => 'nested_upgrade', 'queue.batching.database' => 'nested_upgrade']);
    $migration = '2027_02_07_000001_add_nested_fleet_to_projects.php';
    try {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => basename($path) < $migration));
        Artisan::call('migrate', ['--database' => 'nested_upgrade', '--path' => $paths, '--realpath' => true, '--force' => true]);
        expect(Schema::hasColumn('projects', 'nested_fleet'))->toBeFalse();
        $id = DB::table('projects')->insertGetId(['repository_identity' => 'github.com/hardimpactdev/existing', 'code' => 'EXT', 'name' => 'Existing', 'slug' => 'existing', 'repository_url' => 'https://github.com/hardimpactdev/existing',
            'type' => 'node-package', 'default_branch' => 'main', 'root' => '.', 'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('projects')->find($id);
        Artisan::call('migrate', ['--database' => 'nested_upgrade', '--path' => [database_path('migrations/'.$migration)], '--realpath' => true, '--force' => true]);
        expect((int) DB::table('projects')->where('id', $id)->value('nested_fleet'))->toBe(0);
        DB::table('projects')->where('id', $id)->update(['nested_fleet' => true]);
        Artisan::call('migrate:rollback', ['--database' => 'nested_upgrade', '--step' => 1, '--force' => true]);
        expect(Schema::hasColumn('projects', 'nested_fleet'))->toBeFalse()->and((array) DB::table('projects')->find($id))->toBe($before);
    } finally {
        DB::purge('nested_upgrade');
        config(['database.default' => $previous]);
    }
});

it('requires a JSON boolean for the Node hosting switch', function (mixed $value): void {
    $node = containerDefaultNode();
    $this->patchJson('/api/v1/nodes/'.$node->id, ['nested_fleet' => $value])->assertStatus(422);
    expect($node->refresh()->settings)->not->toHaveKey('nested_fleet');
})->with([['true'], [1], [null]]);

it('stores the Node switch and preserves it through unrelated record updates', function (): void {
    $node = containerDefaultNode();
    $this->getJson('/api/v1/nodes/'.$node->id)->assertOk()->assertJsonPath('data.settings.nested_fleet', false);
    foreach ([true, false] as $enabled) {
        $this->patchJson('/api/v1/nodes/'.$node->id, ['nested_fleet' => $enabled])->assertOk()->assertJsonPath('data.settings.nested_fleet', $enabled);
        $this->getJson('/api/v1/nodes/'.$node->id)->assertOk()->assertJsonPath('data.settings.nested_fleet', $enabled);
        $this->patchJson('/api/v1/nodes/'.$node->id, ['name' => $enabled ? 'enabled' : 'disabled'])->assertOk();
        expect((bool) ($node->refresh()->settings['nested_fleet'] ?? false))->toBe($enabled);
    }
    $this->assertDatabaseCount('operations', 0);
});

it('merges only owned nested settings after successful asynchronous storage preparation', function (string $mode): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $before = $mode !== 'enable';
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => $before, 't3' => ['connection_id' => 'fixture']]]);
    $body = ['apps' => ['path' => '/home/orbit/review-apps']];
    if (in_array($mode, ['enable', 'disable'], true)) {
        $body['nested_fleet'] = ! $before;
    }
    $this->patchJson('/api/v1/nodes/'.$node->id, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input['settings_patch']['nested_fleet'])->toBe(array_key_exists('nested_fleet', $body));
    expect((bool) $node->refresh()->settings['nested_fleet'])->toBe($before);
    if ($mode === 'old capture') {
        $input = $operation->input;
        unset($input['settings_patch']['nested_fleet'], $input['settings']['nested_fleet']);
        $operation->update(['input' => $input]);
    }
    app(NodeSettingsOperationCompletion::class)->complete($operation,
        OperationOutcome::succeeded());
    expect((bool) ($node->refresh()->settings['nested_fleet'] ?? false))->toBe($body['nested_fleet'] ?? $before)
        ->and($node->settings['apps']['path'])->toBe('/home/orbit/review-apps')
        ->and($node->settings['t3'])->toBe(['connection_id' => 'fixture']);
})->with(['enable', 'disable', 'absent', 'old capture']);

/* The one placement rule (docs/reference/projects.md#nested-fleet-setting): every writer of an Instance refuses a nested-fleet Project first. */

const NestedFleetRefusal = 'Only instance:create makes Instances of a nested-fleet Project, as containers.';

const NestedFleetHostRefusal = 'The Node has not switched on nested-fleet hosting.';

/** The flag as the Project update API stores it; a flagged Project's Instance rows below exist only as fixtures. */
function nestedFleetProject(): Project
{
    $project = containerDefaultProject();
    DB::table('projects')->where('id', $project->id)->update(['nested_fleet' => true]);

    return $project->refresh();
}

/** @return array<string, mixed> */
function nestedFleetRows(): array
{
    return ['instances' => DB::table('instances')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'instance_runtimes' => DB::table('instance_runtimes')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'operations' => DB::table('operations')->count(), 'routes' => DB::table('routes')->count(), 'projects' => DB::table('projects')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()];
}

function expectNestedFleetRefusal(Closure $write): void
{
    expect($write)->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(503)->and($error->getMessage())->toBe(NestedFleetRefusal);
    });
}

/** A path that can make a container refuses a nested-fleet Project on a Node whose switch is off (projects.md#nested-fleet-setting). */
function expectNestedFleetHostRefusal(Closure $write): void
{
    expect($write)->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('instance.nested_fleet_host_unavailable')->and($error->status)->toBe(409)->and($error->getMessage())->toBe(NestedFleetHostRefusal);
    });
}

describe('development create', function (): void {
    it('refuses a nested-fleet Project before any record, on a container or a checkout Node', function (string $name, bool $listed): void {
        $node = containerDefaultNode(listed: $listed);
        $project = nestedFleetProject();
        if ($name !== 'default') {
            Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/home/orbit/apps/tool/default', 'status' => 'active']);
        }
        $before = nestedFleetRows();
        expectNestedFleetHostRefusal(fn () => containerCreate($project, $node, $name));
        expect(nestedFleetRows())->toBe($before);
        Queue::assertNothingPushed();
    })->with(['default' => ['default'], 'other Instance' => ['feature']])->with(['build-list Node' => [true], 'checkout Node' => [false]]);

    it('refuses before it captures missing source facts', function (): void {
        $node = containerDefaultNode();
        $project = nestedFleetProject();
        DB::table('projects')->where('id', $project->id)->update(['source_facts_captured_at' => null]);
        $before = nestedFleetRows();
        expectNestedFleetHostRefusal(fn () => containerCreate($project, $node));
        expect(nestedFleetRows())->toBe($before);
    });

    it('refuses under the create transaction when the flag changes while admission reads the branches', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $runner = Mockery::mock(ProcessRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function () use ($project): CommandResult {
            // The flag changes only while the Project has no Instance, which is the case during this read.
            DB::table('projects')->where('id', $project->id)->update(['nested_fleet' => true]);

            return new CommandResult(0, str_repeat('a', 40)."\trefs/heads/main\n", '', 1, false);
        });
        app()->instance(ProcessRunner::class, $runner);
        $before = nestedFleetRows();
        expectNestedFleetHostRefusal(fn () => app(StartDevelopmentInstanceCreateAction::class)->execute(new CreateInstanceData($project->id, $node->id, 'feature', null, null, null,
            branchSelection: new InstanceBranchSelection('new', 'feature', 'main')), null, null));
        expect(Arr::except(nestedFleetRows(), 'projects'))->toBe(Arr::except($before, 'projects'));
    });

    it('refuses a retry of a retained failed create and leaves its rows as they are', function (bool $container): void {
        $node = containerDefaultNode();
        $project = nestedFleetProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
            'checkout_path' => '/home/orbit/apps/tool/default', 'status' => 'reserved', 'first_setup_pending' => true,
            'failed_step' => 'create', 'error_code' => 'instance.create_failed']);
        if ($container) {
            DB::table('projects')->where('id', $project->id)->update(['nested_fleet' => false]);
            app(InstanceContainer::class)->reserve($instance, $node);
            DB::table('projects')->where('id', $project->id)->update(['nested_fleet' => true]);
        }
        $before = nestedFleetRows();
        expectNestedFleetHostRefusal(fn () => containerCreate($project->refresh(), $node));
        expect(nestedFleetRows())->toBe($before);
    })->with(['checkout' => [false], 'container' => [true]]);

    it('admits a normal Project as before', function (): void {
        SwampNodeFixture::ensure();
        $node = containerDefaultNode();
        $operation = containerCreate(containerDefaultProject(), $node);
        expect($operation->kind)->toBe('instance.create')->and(containerIntentOf($operation))->not->toBeNull();
        expect(InstanceRuntime::query()->count())->toBe(1)->and(Instance::query()->count())->toBe(1);
    });

    it('admits a nested-fleet default on a switched-on build-list Node, in the nested project, from the nested-fleet image', function (): void {
        SwampNodeFixture::ensure();
        $node = containerDefaultNode();
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
        $operation = containerCreate(nestedFleetProject(), $node);
        $intent = containerIntentOf($operation);
        expect($operation->kind)->toBe('instance.create')
            ->and(InstanceRuntime::query()->sole()->incus_project)->toBe('orbit-instances-nested')
            ->and($intent['project'] ?? null)->toBe('orbit-instances-nested')
            ->and($intent['image'] ?? null)->toBe('orbit-instance-nested')
            ->and($intent['config']['security.nesting'] ?? null)->toBe('true');
    });

    it('refuses a nested-fleet Project on a switched-on Node off the build list before any record', function (): void {
        $node = containerDefaultNode(listed: false);
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
        $project = nestedFleetProject();
        $before = nestedFleetRows();
        expect(fn () => containerCreate($project, $node))->toThrow(function (ResourceOperationException $error): void {
            expect($error->errorCode)->toBe('instance.nested_fleet_container_required')->and($error->status)->toBe(409);
        });
        expect(nestedFleetRows())->toBe($before);
        Queue::assertNothingPushed();
    });
});

describe('the runtime row', function (): void {
    it('refuses to reserve a runtime for an Instance of a nested-fleet Project', function (): void {
        $node = containerDefaultNode();
        $project = nestedFleetProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        expectNestedFleetHostRefusal(fn () => app(InstanceContainer::class)->reserve($instance, $node));
        $this->assertDatabaseCount('instance_runtimes', 0);
    });
});

describe('migrate', function (): void {
    it('refuses a checkout of a nested-fleet Project before it reserves a runtime or records an Operation', function (): void {
        $node = containerDefaultNode();
        $project = nestedFleetProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
            'checkout_path' => '/home/orbit/apps/tool/default', 'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false, 'branch' => 'main']);
        $before = nestedFleetRows();
        expectNestedFleetRefusal(fn () => app(StartInstanceMigrateAction::class)->execute($instance, null, null));
        expect(nestedFleetRows())->toBe($before);
        Queue::assertNothingPushed();
    });
});

describe('production clone', function (): void {
    it('refuses a candidate of a nested-fleet Project before target reservation', function (bool $nested): void {
        SwampNodeFixture::ensure();
        $node = containerDefaultNode();
        $target = containerDefaultNode('production', '10.44.0.9', false);
        $target->roles()->create(['role' => 'app-prod', 'status' => 'active']);
        $project = $nested ? nestedFleetProject() : containerDefaultProject();
        $candidate = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
            'checkout_path' => '/test/default', 'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false,
            'source_is_laravel' => false, 'branch' => 'main']);
        $before = nestedFleetRows();
        $clone = fn () => app(StartInstanceCloneAction::class)->execute($candidate, new CloneInstanceData($target->id, 'production', 'production', null, null));
        if ($nested) {
            expectNestedFleetRefusal($clone);
            expect(nestedFleetRows())->toBe($before);

            return;
        }
        // A normal candidate passes the rule and reaches the checks that follow it, as before.
        expect($clone)->toThrow(fn (ResourceOperationException $error) => expect($error->getMessage())->not->toBe(NestedFleetRefusal));
    })->with(['nested-fleet' => [true], 'normal' => [false]]);
});

describe('register', function (): void {
    it('admits a normal checkout and refuses a nested-fleet Project at both admission reads', function (string $mode): void {
        $this->freezeTime();
        $node = containerDefaultNode();
        Node::withoutEvents(fn () => $node->update(['ssh_host_fingerprint' => 'fixture']));
        $project = $mode === 'early' ? nestedFleetProject() : containerDefaultProject();
        $path = '/home/orbit/apps/tool/default';
        NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'checkout', 'subject' => $path,
            'digest' => str_repeat('a', 64), 'observed_at' => now(), 'received_at' => now(),
            'data' => ['application' => $path, 'top_level' => $path, 'common_dir' => $path.'/.git', 'linked' => false,
                'worktrees' => [$path], 'origin' => $project->repository_url, 'owner' => 'orbit', 'head' => str_repeat('c', 40),
                'branch' => 'main', 'default_branch' => 'main', 'artisan' => 'absent', 'composer' => null, 'env' => null]]);
        $view = Mockery::mock(AgentStateView::class);
        if ($mode === 'early') {
            $view->shouldNotReceive('subscriber');
        } else {
            $clock = (float) now()->format('U.u');
            $view->shouldReceive('subscriber')->andReturn(new AgentViewSubscriberHealth($clock, true, true, 1));
            $view->shouldReceive('node')->with($node->id)->andReturnUsing(function () use ($mode, $project, $node, $clock): AgentNodeView {
                if ($mode === 'during observation') {
                    // The flag changes only while the Project has no Instance, which is the case during this observation.
                    DB::table('projects')->where('id', $project->id)->update(['nested_fleet' => true]);
                }

                return new AgentNodeView($node->id, AgentViewFreshness::Fresh, receivedAt: $clock);
            });
        }
        app()->instance(AgentStateView::class, $view);
        $register = fn () => app(RegisterInstanceAction::class)->execute($node, new RegisterInstanceData($path, false, $project->id, 'default', null, null));
        if ($mode === 'normal') {
            $result = $register();
            expect($result)->toBeInstanceOf(Instance::class)->and($result->checkout_path)->toBe($path)->and($result->status->value)->toBe('active');
            $this->assertDatabaseCount('instances', 1);
        } else {
            expectNestedFleetRefusal($register);
            $this->assertDatabaseCount('instances', 0);
            Queue::assertNothingPushed();
        }
        $this->assertDatabaseCount('instance_runtimes', 0);
        expect(Operation::query()->count())->toBe(0);
    })->with(['normal', 'early', 'during observation']);
});
