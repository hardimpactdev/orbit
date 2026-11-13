<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceContainer;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Models\Instance;
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
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true, 'name' => 'Rejected'])
            ->assertStatus(409)->assertJsonPath('error.code', 'project.nested_fleet_not_allowed');
        expect($project->refresh()->nested_fleet)->toBeFalse()->and($project->name)->toBe('tool');
        $this->assertDatabaseCount('operations', 0);
        Queue::assertNothingPushed();
    })->with(['default empty', 'other Project']);

    it('leaves every other Project field unchanged when the flag is refused', function (array $other, string $field): void {
        $project = containerDefaultProject();
        $before = $project->refresh()->getRawOriginal($field);
        $this->patchJson('/api/v1/projects/'.$project->id, [...$other, 'nested_fleet' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'project.nested_fleet_not_allowed');
        expect($project->refresh()->getRawOriginal($field))->toBe($before)->and($project->nested_fleet)->toBeFalse();
        $this->assertDatabaseCount('operations', 0);
    })->with([
        'code' => [['code' => 'NEW'], 'code'],
    ]);

    it('refuses an invalid operator trial allow list', function (mixed $list): void {
        $project = containerDefaultProject();
        config(['instances.nested_fleet_projects' => $list]);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])
            ->assertStatus(500)->assertJsonPath('error.code', 'project.nested_fleet_projects_invalid');
        expect($project->refresh()->nested_fleet)->toBeFalse();
        Queue::assertNothingPushed();
    })->with(['malformed JSON' => [null], 'object' => [['id' => 1]], 'string ID' => [['1']], 'zero ID' => [[0]]]);

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
