<?php

declare(strict_types=1);

use App\Actions\Instances\RegisterInstanceAction;
use App\Actions\Instances\StartInstanceCloneAction;
use App\Data\Instances\CloneInstanceData;
use App\Data\Instances\RegisterInstanceData;
use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

describe('nested-fleet Project admission', function (): void {
    it('defaults off and updates through the API without source reads or Operations', function (): void {
        $project = containerDefaultProject();
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertOk()->assertJsonPath('data.nested_fleet', true);
        $this->patchJson('/api/v1/projects/'.$project->id, ['name' => 'New name'])->assertOk()->assertJsonPath('data.nested_fleet', true);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => false])->assertOk()->assertJsonPath('data.nested_fleet', false);
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->assertDatabaseCount('operations', 0);
    });
    it('requires a JSON boolean', function (mixed $value): void {
        $project = containerDefaultProject();
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => $value])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['details' => ['nested_fleet']]]);
        expect($project->refresh()->nested_fleet)->toBeFalse();
    })->with(['text' => ['true'], 'integer' => [1], 'null' => [null]]);
    it('refuses a placement change once the Project has any Instance', function (bool $container): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        if ($container) {
            app(InstanceContainer::class)->reserve($instance, $node);
        }
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertStatus(409)->assertJsonPath('error.code', 'project.nested_fleet_in_use');
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => false])->assertOk();
    })->with([false, true]);
});

it('refuses nested checkout migration before reserving a runtime or Operation', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/test/default', 'status' => 'active', 'first_setup_pending' => false]);
    $this->postJson('/api/v1/instances/'.$instance->id.'/migrate')->assertStatus(503)
        ->assertJsonPath('error.code', 'operation.kind_unavailable')
        ->assertJsonPath('error.message', 'Checkout migration into a nested-fleet container is not available.');
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
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

it('refuses every nested create before reservation regardless of the Node switch', function (string $name, bool $enabled): void {
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => $enabled]]);
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    expect(fn () => containerCreate($project, $node, $name))->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(503);
    });
    $this->assertDatabaseCount('instances', 0);
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
})->with(['default', 'feature'])->with([false, true]);

it('stores the Node switch and preserves it through unrelated record updates', function (): void {
    $node = containerDefaultNode();
    foreach ([true, false] as $enabled) {
        $this->patchJson('/api/v1/nodes/'.$node->id, ['nested_fleet' => $enabled])->assertOk();
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

it('refuses production cloning of a nested checkout before target reservation', function (): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $target = containerDefaultNode('production', '10.44.0.9', false);
    $target->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    $candidate = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/test/default', 'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false,
        'source_is_laravel' => false, 'branch' => 'main']);
    expect(fn () => app(StartInstanceCloneAction::class)->execute($candidate,
        new CloneInstanceData($target->id, 'production', 'production', null, null)))
        ->toThrow(function (ResourceOperationException $error): void {
            expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(503)
                ->and($error->getMessage())->toBe('Production cloning of nested-fleet Instances is not available.');
        });
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
});

it('admits normal checkout registration and refuses nested registration at both admission reads', function (string $mode): void {
    $this->freezeTime();
    $node = containerDefaultNode();
    Node::withoutEvents(fn () => $node->update(['ssh_host_fingerprint' => 'fixture']));
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => $mode === 'early']);
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
                $project->update(['nested_fleet' => true]);
            }

            return new AgentNodeView($node->id, AgentViewFreshness::Fresh, receivedAt: $clock);
        });
    }
    app()->instance(AgentStateView::class, $view);
    $register = fn () => app(RegisterInstanceAction::class)->execute($node,
        new RegisterInstanceData($path, false, $project->id, 'default', null, null));
    if ($mode === 'normal') {
        $result = $register();
        expect($result)->toBeInstanceOf(Instance::class)->and($result->checkout_path)->toBe($path)
            ->and($result->status->value)->toBe('active');
        $this->assertDatabaseCount('instances', 1);
    } else {
        expect($register)->toThrow(function (ResourceOperationException $error): void {
            expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(503);
        });
        expect($project->refresh()->nested_fleet)->toBeTrue();
        $this->assertDatabaseCount('instances', 0);
        Queue::assertNothingPushed();
    }
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
})->with(['normal', 'early', 'during observation']);
