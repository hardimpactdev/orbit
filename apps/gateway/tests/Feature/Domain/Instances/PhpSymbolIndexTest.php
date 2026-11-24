<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\PhpSymbolIndex;
use App\Domain\Instances\PhpSymbolIndexRefresh;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
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
    $create = containerCreate($project, $node);
    // The create ended: its Operation holds the Instance no longer.
    $create->update(['status' => 'succeeded', 'lock_key' => null]);
    $default = Instance::query()->findOrFail($create->target_id);
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

it('indexes a Laravel default without vendor/ too: eligibility reads no Composer install', function (): void {
    // A default whose deploy steps never ran composer install has no vendor/ (as Beast's default 2120): its index holds the source.
    $default = phpIndexDefault(containerDefaultNode());

    expect(ProjectDevelopmentDeployStep::query()->where('project_id', $default->project_id)->exists())->toBeFalse()
        ->and(PhpSymbolIndex::intent($default))->toBe(['root' => InstanceHostIntent::Workspace, 'seconds' => PhpSymbolIndex::Seconds]);
});

it('runs only the index and seed phases of a running Laravel default, with no deployment, checkout or deploy step', function (): void {
    $default = phpIndexDefault(containerDefaultNode());

    $operation = app(PhpSymbolIndexRefresh::class)->start($default, null, null);

    expect($operation->kind)->toBe(PhpSymbolIndexRefresh::Kind)
        ->and(app(DirectOperation::class)->workflowFor($operation))->toBe('operation-instance-php-index')
        ->and(array_keys($operation->input))->toBe(['container'])
        ->and($operation->input['container']['php_index'])->toBe(['root' => InstanceHostIntent::Workspace, 'seconds' => PhpSymbolIndex::Seconds])
        // The seed it retakes records the clone files and the tree's state, as a deployment's seed does.
        ->and($operation->input['container'])->toHaveKeys(['seed_files', 'services_digest'])
        ->and($operation->input['container'])->not->toHaveKey('steps');
});

it('refuses the index refresh of an Instance that gets no index, holds no seed, or is not running', function (Closure $change, string $code): void {
    $default = phpIndexDefault(containerDefaultNode());
    $change($default);

    try {
        app(PhpSymbolIndexRefresh::class)->start(Instance::query()->findOrFail($default->id), null, null);
        $this->fail('The refresh was admitted.');
    } catch (ResourceOperationException $refusal) {
        expect($refusal->errorCode)->toBe($code)
            ->and(Operation::query()->where('kind', PhpSymbolIndexRefresh::Kind)->exists())->toBeFalse();
    }
})->with([
    'a source that is not Laravel' => [static function (Instance $default): void {
        $default->update(['source_is_laravel' => false]);
    }, 'instance.php_index_ineligible'],
    'an app-prod Node' => [static function (Instance $default): void {
        $default->node->roles()->update(['role' => RoleName::AppProd]);
    }, 'instance.php_index_ineligible'],
    'a legacy host checkout, with no container or seed' => [static function (Instance $default): void {
        $default->runtime()->delete();
    }, 'operation.kind_unavailable'],
    'a stopped container' => [static function (Instance $default): void {
        $default->runtime()->update(['power_state' => InstanceRuntimePowerState::Stopped]);
    }, 'process.target_inactive'],
    'a default with no seed yet' => [static function (Instance $default): void {
        $default->runtime()->update(['seed_commit' => null]);
    }, 'instance.lifecycle_busy'],
]);

it('admits each eligible default from the artisan entry point and names each refused one with its code', function (): void {
    $node = containerDefaultNode();
    $default = phpIndexDefault($node);

    $this->artisan('instances:php-index', ['instance' => [(string) $default->id, '999999']])
        ->expectsOutputToContain('"instance":'.$default->id.',"operation":')
        ->expectsOutputToContain('"skipped":"instance.not_found"')
        ->assertExitCode(1);
    expect(Operation::query()->where('kind', PhpSymbolIndexRefresh::Kind)->count())->toBe(1);
});
