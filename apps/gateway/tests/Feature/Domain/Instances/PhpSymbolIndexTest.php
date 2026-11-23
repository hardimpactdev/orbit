<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\PhpSymbolIndex;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ContainerInstanceHelpers.php';

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    workspaceReader();
});

/** A Laravel Project's container default, as its create admits it, active with a seed. A monorepo type: it needs no Route. */
function phpIndexDefault(Node $node, string $root = 'public', bool $laravel = true): Instance
{
    $project = containerDefaultProject(ProjectType::Monorepo, '8.5');
    $project->update(['root' => $root, 'is_laravel' => $laravel]);
    $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

it('prepares the index of a Laravel default on an active app-dev Node at its artisan folder, from its first seed on', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::Monorepo, '8.5');
    $project->update(['root' => 'public', 'is_laravel' => true]);
    $create = containerCreate($project, $node);
    $default = Instance::query()->findOrFail($create->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    $index = ['root' => InstanceHostIntent::Workspace, 'seconds' => PhpSymbolIndex::Seconds];

    // The create's seed and every deployment's seed after it, a repeat refresh of the same commit too: the same root, so Phpactor in
    // the container finds the same index and reindexes only what changed, and the same bound, which each run's budget holds.
    expect(containerIntentOf($create)['php_index'])->toBe($index)
        ->and(app(InstanceDeploymentIntent::class)->snapshot($default->refresh())['container']['php_index'])->toBe($index)
        ->and(app(InstanceDeploymentIntent::class)->snapshot($default->refresh())['container']['php_index'])->toBe($index)
        ->and(PhpSymbolIndex::runSeconds(containerIntentOf($create)))->toBe(PhpSymbolIndex::Seconds + PhpSymbolIndex::ExecSeconds)
        ->and($create->input['run_budget_seconds'])->toBe(1800 + PhpSymbolIndex::Seconds + PhpSymbolIndex::ExecSeconds);
});

it('names a nested Laravel application\'s folder, not the workspace, as the index root', function (): void {
    $default = phpIndexDefault(containerDefaultNode(), 'apps/shop/public');

    expect(PhpSymbolIndex::intent($default->refresh()))->toBe(['root' => InstanceHostIntent::Workspace.'/apps/shop', 'seconds' => PhpSymbolIndex::Seconds]);
});

it('gives the index only to a Laravel default placed on an active app-dev Node', function (Closure $change): void {
    $node = containerDefaultNode();
    $default = phpIndexDefault($node);
    expect(PhpSymbolIndex::intent($default))->not->toBeNull();
    $change($default, $node);
    $default = Instance::query()->findOrFail($default->id);

    expect(PhpSymbolIndex::intent($default))->toBeNull();
    // Only a default on app-dev deploys at all; there its seed takes no index.
    if ($default->placedOnAppDev() && $default->node->roles->contains('status', 'active')) {
        expect(app(InstanceDeploymentIntent::class)->snapshot($default)['container'])->not->toHaveKey('php_index');
    }
})->with([
    'a source that is not Laravel' => [static function (Instance $default): void {
        $default->update(['source_is_laravel' => false]);
    }],
    'a production Node: app-prod in place of app-dev' => [static function (Instance $default, Node $node): void {
        $node->roles()->update(['role' => RoleName::AppProd]);
    }],
    'a Node with both app roles, placed nowhere' => [static function (Instance $default, Node $node): void {
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    }],
    'a Node with no app role, such as a CI Node' => [static function (Instance $default, Node $node): void {
        $node->roles()->delete();
    }],
    'an app-dev role that is not active' => [static function (Instance $default, Node $node): void {
        $node->roles()->update(['status' => 'failed']);
    }],
]);

it('gives a clone of a Laravel default no index work of its own: it copies the seed\'s index', function (): void {
    $node = containerDefaultNode();
    $default = phpIndexDefault($node);
    $clone = containerCreate($default->project, $node, 'feature');

    expect(containerIntentOf($clone))->not->toHaveKey('php_index')
        ->and(PhpSymbolIndex::intent(Instance::query()->findOrFail($clone->target_id)))->toBeNull()
        ->and(PhpSymbolIndex::runSeconds(containerIntentOf($clone)))->toBe(0);
});

it('gives a non-Laravel default neither index work nor its time in the budget', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $create = containerCreate($project, $node);

    expect(containerIntentOf($create))->not->toHaveKey('php_index')
        ->and($create->input['run_budget_seconds'])->toBe(1800);
});

it('keeps production out: a Project default on an app-prod Node has no container intent to carry the index', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'prod', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9']));
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'shop', 'slug' => 'shop', 'type' => ProjectType::LaravelApp, 'repository_url' => 'https://github.com/nckrtl/shop.git',
        'default_branch' => 'main', 'root' => 'public', 'is_laravel' => true]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/shop/default',
        'status' => InstanceState::Active, 'source_is_laravel' => true]);

    expect($instance->placedOnAppProd())->toBeTrue()
        ->and(PhpSymbolIndex::intent($instance))->toBeNull();
});
