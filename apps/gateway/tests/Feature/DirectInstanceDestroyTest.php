<?php

declare(strict_types=1);

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Domain\GitHub\GitHubCliToken;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\InstanceDestroyProjection;
use App\Domain\Instances\InstanceDestroyTeardownSecret;
use App\Domain\Instances\InstanceLifecycleGitSecret;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationResultProjection;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskGroupEndingIntent;
use App\Domain\Tasks\TaskGroupEndingProjection;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Cluster;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Destroy proof', 'slug' => 'destroy-proof',
        'repository_url' => 'https://github.com/example/destroy-proof.git', 'root' => 'public']);
    $this->node = Node::query()->create(['name' => 'destroy-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/destroy-proof/proof', 'source_layout' => 'checkout', 'status' => 'reserved']);
    $this->command = 'printf "%s" private-teardown-proof-438824';
    $this->step = ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'teardown',
        'position' => 0, 'name' => 'release-resource', 'command' => $this->command, 'timeout_seconds' => 30]);
    $this->operation = new Operation(['kind' => 'instance.destroy', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'input' => [
            'environment' => 'development', 'instance_id' => $this->instance->id, 'node_id' => $this->node->id,
            'project_id' => $this->project->id, 'direct_destroy' => ['node_user' => $this->node->user, 'teardown' => [[
                'instance_id' => $this->instance->id, 'checkout' => $this->instance->checkout_path,
                'user' => $this->node->user, 'steps' => [[
                    'name' => $this->step->name, 'timeout_seconds' => 30, 'sha256' => hash('sha256', $this->command),
                ]],
            ]]],
        ]]);
});

it('resolves only the admitted teardown scripts in a protected memory value', function (): void {
    $value = app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown');

    expect($value)->toBeInstanceOf(OperationSecretValue::class)
        ->and($value->value)->toBe(['members' => [['instance_id' => $this->instance->id,
            'steps' => [['name' => 'release-resource', 'timeout_seconds' => 30, 'command' => $this->command]]]]])
        ->and($value->strings())->toContain($this->command)
        ->and(json_encode($value))->toBe('"[REDACTED]"')
        ->and(print_r($value, true))->not->toContain($this->command)
        ->and(json_encode($this->operation->toArray()))->not->toContain($this->command);
});

it('refuses scripts changed since admission', function (array $change): void {
    $this->step->update($change);

    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown'))
        ->toThrow(ResourceOperationException::class);
})->with([
    'command' => [['command' => 'echo replacement']],
    'timeout' => [['timeout_seconds' => 31]],
    'name' => [['name' => 'replacement']],
]);

it('does not hydrate newly added teardown steps', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->project->id, 'phase' => 'teardown',
        'position' => 1, 'name' => 'extra', 'command' => 'echo unadmitted', 'timeout_seconds' => 30]);

    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown'))
        ->toThrow(ResourceOperationException::class);
});

it('refuses an unadmitted dev teardown account', function (string $user): void {
    $input = $this->operation->input;
    $input['direct_destroy']['teardown'][0]['user'] = $user;
    $this->operation->input = $input;

    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown'))
        ->toThrow(ResourceOperationException::class);
})->with(['root', 'nckrtl', 'orbit-app-1']);

it('keeps the admitted dev account fixed after a Node user change', function (): void {
    $this->node->update(['user' => 'replacement']);

    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown'))
        ->toThrow(ResourceOperationException::class);
});

it('keeps pre-activation teardown empty even when the Project has scripts', function (): void {
    $input = $this->operation->input;
    $input['direct_destroy']['teardown'][0]['steps'] = [];
    $this->operation->input = $input;

    expect(app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown')->value)
        ->toBe(['members' => [['instance_id' => $this->instance->id, 'steps' => []]]]);
});

it('refuses teardown after the owning Instance changes placement', function (): void {
    $other = Node::query()->create(['name' => 'other-dev', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);
    $this->instance->update(['node_id' => $other->id]);

    expect(app(InstanceDestroyTeardownSecret::class)->ownerId($this->operation))->toBeNull();
    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown'))
        ->toThrow(ResourceOperationException::class);
});

it('refuses another member checkout before exposing scripts', function (): void {
    $input = $this->operation->input;
    $input['direct_destroy']['teardown'][0]['checkout'] = '/srv/apps/foreign/proof';
    $this->operation->input = $input;

    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown'))
        ->toThrow(ResourceOperationException::class);
});

it('refuses caller-selected script keys and parameters', function (string $key, string $parameter): void {
    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, $key, $parameter))
        ->toThrow(ResourceOperationException::class);
})->with([['teardown.release-resource', 'teardown'], ['', 'program']]);

it('refuses another lifecycle kind before exposing teardown', function (): void {
    $this->operation->kind = 'instance.create';

    expect(app(InstanceDestroyTeardownSecret::class)->ownerId($this->operation))->toBeNull();
    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($this->operation, $this->instance->id, '', 'teardown'))
        ->toThrow(ResourceOperationException::class);
});

it('refuses managed database artifacts before recording any removal intent', function (string $driver): void {
    $server = DatabaseServer::query()->create(['slug' => 'managed-proof', 'node_id' => $this->node->id,
        'driver' => $driver, 'tag' => 'test', 'port' => 3306, 'root_password' => 'protected-server-password', 'status' => 'active']);
    $connection = DatabaseConnection::query()->create(['slug' => 'managed-proof', 'driver' => $driver,
        'database_server_id' => $server->id, 'owner_instance_id' => $this->instance->id]);

    expect(fn () => app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance)))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.native_removal_unavailable'));

    expect($this->instance->refresh()->status->value)->toBe('reserved')
        ->and(InstanceRemoval::query()->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(0);
    $this->assertModelExists($connection);
})->with(['mysql', 'pgsql', 'redis']);

it('stores the owning SQLite artifact and records desired state without executing a child', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'proof-sqlite', 'driver' => 'sqlite',
        'owner_instance_id' => $this->instance->id, 'node_id' => $this->node->id,
        'path' => $this->instance->checkout_path.'/database.sqlite', 'password' => 'never-in-intent']);
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $input = directDestroyFixture($this->instance);

    $recorded = app(DirectInstanceDestroyIntent::class)->record($input);

    expect($recorded['direct_destroy']['databases'])->toHaveCount(1)
        ->and($recorded['direct_destroy']['databases'][0]['owner_instance_id'])->toBe($this->instance->id)
        ->and($recorded['direct_destroy']['databases'][0]['connections'])->toBe([[
            'connection_id' => $connection->id, 'slug' => $connection->slug, 'driver' => 'sqlite',
            'node_id' => $this->node->id, 'path' => $connection->path,
        ]])->and($recorded['direct_destroy']['runtime'])->toBe(['processes' => [], 'schedules' => []])
        ->and($recorded['direct_destroy']['node_user'])->toBe($this->node->user)
        ->and($recorded['direct_destroy']['teardown'][0]['user'])->toBe($this->node->user)
        ->and($recorded['direct_destroy']['teardown'][0]['steps'])->toBe([])
        ->and($recorded['direct_destroy']['members'][0]['worktrees'])->toBe($input['members'][0]['worktree_inventory'])
        ->and($recorded['direct_destroy']['members'][0]['source']['operation'])->toBe($input['removal_id'])
        ->and($recorded['direct_destroy']['routes'][0]['schema'])->toBe('route.withdrawal.v1')
        ->and($recorded['direct_destroy']['routes'][0]['route_ids'])->toBe([])
        ->and(json_encode($recorded['direct_destroy']['routes'][0]['php']['configurations']))->toBe('{}')
        ->and($recorded['direct_destroy']['routes'][0]['dns'])->toBe([])
        ->and($this->instance->refresh()->status->value)->toBe('removing')
        ->and(Operation::query()->count())->toBe(0)
        ->and(json_encode($recorded))->not->toContain('never-in-intent', $this->command);
    $this->assertModelExists($connection);
});

it('admits the complete database set before writing the first removal member', function (): void {
    $other = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'other', 'checkout_path' => '/srv/apps/destroy-proof/other', 'source_layout' => 'worktree', 'status' => 'reserved']);
    $server = DatabaseServer::query()->create(['slug' => 'second-member', 'node_id' => $this->node->id,
        'tag' => 'test', 'port' => 3306, 'root_password' => 'protected-server-password', 'status' => 'active']);
    DatabaseConnection::query()->create(['slug' => 'second-member', 'driver' => 'mysql',
        'database_server_id' => $server->id, 'owner_instance_id' => $other->id]);
    $input = directDestroyFixture($this->instance);
    $input['members'][] = directDestroyFixture($other)['members'][0];

    expect(fn () => app(DirectInstanceDestroyIntent::class)->record($input))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.native_removal_unavailable'));

    expect(InstanceRemoval::query()->count())->toBe(0)
        ->and($this->instance->refresh()->status->value)->toBe('reserved')
        ->and($other->refresh()->status->value)->toBe('reserved');
});

it('keeps its database snapshot closed to ownership saves after removal intent commits', function (string $change): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $connection = DatabaseConnection::query()->create(['slug' => 'snapshot-owned', 'driver' => 'sqlite',
        'owner_instance_id' => $this->instance->id, 'node_id' => $this->node->id,
        'path' => $this->instance->checkout_path.'/database.sqlite']);
    $recorded = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $pending = match ($change) {
        'new' => new DatabaseConnection(['slug' => 'late-owned', 'driver' => 'sqlite',
            'owner_instance_id' => $this->instance->id, 'node_id' => $this->node->id,
            'path' => $this->instance->checkout_path.'/late.sqlite']),
        'reassign' => $connection->fill(['owner_instance_id' => null]),
        'path' => $connection->fill(['path' => $this->instance->checkout_path.'/changed.sqlite']),
    };

    expect(fn () => $pending->save())
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.owner_removing'));

    expect(DatabaseConnection::query()->count())->toBe(1)
        ->and($connection->refresh()->owner_instance_id)->toBe($this->instance->id)
        ->and($connection->path)->toBe($this->instance->checkout_path.'/database.sqlite')
        ->and(array_column($recorded['direct_destroy']['databases'][0]['connections'], 'connection_id'))->toBe([$connection->id])
        ->and($this->instance->refresh()->status->value)->toBe('removing');
})->with(['new', 'reassign', 'path']);

it('refuses an incomplete retained source snapshot instead of inventing inventory', function (string $field): void {
    $input = directDestroyFixture($this->instance);
    unset($input['members'][0][$field]);

    expect(fn () => app(DirectInstanceDestroyIntent::class)->record($input))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.removal_conflict'));
    expect(InstanceRemoval::query()->count())->toBe(0);
})->with(['worktree_inventory', 'origin']);

it('captures complete Route withdrawal artifacts without detaching their targets', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $routes = [];
    foreach ([null, 'apps/docs/public'] as $index => $webRoot) {
        $route = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
            'domain' => 'destroy-'.$index.'.test', 'web_root' => $webRoot, 'provenance' => 'explicit',
            'publication' => 'private', 'status' => 'pending', 'sites_published' => false]);
        $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
        $routes[] = $route;
    }
    $input = directDestroyFixture($this->instance);
    $input['members'][0]['route_id'] = $routes[0]->id;
    $input['additional_route_ids'] = [$routes[1]->id];

    $recorded = app(DirectInstanceDestroyIntent::class)->record($input);

    $artifact = $recorded['direct_destroy']['routes'][0];
    expect($artifact['schema'])->toBe('route.withdrawal.v1')
        ->and($artifact['instance_id'])->toBe($this->instance->id)
        ->and($artifact['route_ids'])->toBe(array_column($routes, 'id'))
        ->and($artifact['nodes'][0]['certificate_scopes'])->toContain('route-'.$routes[1]->id)
        ->and($recorded['direct_destroy']['publication_node_ids'])->toBe([$this->node->id])
        ->and(Operation::query()->count())->toBe(0);
    foreach ($routes as $route) {
        expect($route->refresh()->status->value)->toBe('retiring')
            ->and($route->sites_published)->toBeFalse()
            ->and($route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id]);
    }
});

it('refuses a Route added after the source snapshot before writing removal intent', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $input = directDestroyFixture($this->instance);
    $route = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'domain' => 'late-route.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);

    expect(fn () => app(DirectInstanceDestroyIntent::class)->record($input))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.removal_conflict'));
    expect($this->instance->refresh()->status->value)->toBe('reserved')
        ->and(InstanceRemoval::query()->count())->toBe(0)
        ->and($route->refresh()->status->value)->toBe('pending');
});

it('rolls back all intent writes when the owning Route artifact refuses a Node', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $this->node->update(['status' => 'removing']);

    expect(fn () => app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance)))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.withdrawal_node_unavailable'));
    expect($this->instance->refresh()->status->value)->toBe('reserved')
        ->and(InstanceRemoval::query()->count())->toBe(0);

    $connection = DatabaseConnection::query()->create(['slug' => 'after-refused-removal', 'driver' => 'sqlite',
        'owner_instance_id' => $this->instance->id, 'node_id' => $this->node->id,
        'path' => $this->instance->checkout_path.'/database.sqlite']);
    $this->assertModelExists($connection);
});

it('records workload, current and transition Router and VPN placements from the owning artifacts', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $routers = [];
    foreach (['current', 'transition'] as $index => $name) {
        $cluster = Cluster::query()->create(['name' => $name]);
        $router = Node::query()->create(['name' => $name, 'cluster_id' => $cluster->id,
            'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'amd64', 'status' => 'active',
            'public_ssh_host' => '192.0.2.'.($index + 17), 'wireguard_ip' => '10.44.0.'.($index + 17)]);
        $router->roles()->create(['role' => 'router', 'status' => 'active', 'cluster_id' => $cluster->id]);
        $routers[] = [$cluster, $router];
    }
    $this->node->update(['cluster_id' => $routers[0][0]->id]);
    $vpn = Node::query()->create(['name' => 'vpn', 'user' => 'orbit', 'platform' => 'linux',
        'architecture' => 'amd64', 'status' => 'active', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']);
    $vpn->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $this->project->id, 'cluster_id' => $routers[0][0]->id,
        'transition_cluster_id' => $routers[1][0]->id, 'domain' => 'cluster-destroy.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending', 'sites_published' => false]);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $input = directDestroyFixture($this->instance);
    $input['members'][0]['route_id'] = $route->id;

    $recorded = app(DirectInstanceDestroyIntent::class)->record($input);

    expect($recorded['direct_destroy']['publication_node_ids'])->toBe([$this->node->id, $routers[0][1]->id, $routers[1][1]->id, $vpn->id])
        ->and(array_column($recorded['direct_destroy']['routes'][0]['nodes'], 'node_id'))->toBe([$this->node->id, $routers[0][1]->id, $routers[1][1]->id])
        ->and(array_column($recorded['direct_destroy']['routes'][0]['dns'], 'node_id'))->toBe([$vpn->id])
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id])
        ->and(Operation::query()->count())->toBe(0);
});

it('rolls back Route and Instance intent outside the private withdrawal contract', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $route = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'domain' => 'public-destroy.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $cluster = Cluster::query()->create(['name' => 'public-scope']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $route->update(['node_id' => null, 'cluster_id' => $cluster->id, 'publication' => 'public']);
    $input = directDestroyFixture($this->instance);
    $input['members'][0]['route_id'] = $route->id;

    expect(fn () => app(DirectInstanceDestroyIntent::class)->record($input))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.withdrawal_invalid'));
    expect($route->refresh()->status->value)->toBe('pending')
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id])
        ->and($this->instance->refresh()->status->value)->toBe('reserved')
        ->and(InstanceRemoval::query()->count())->toBe(0);
});

it('projects the complete native removal atomically including extra Routes and owned runtime', function (): void {
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $neighbor = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'neighbor', 'checkout_path' => '/srv/apps/destroy-proof/neighbor', 'source_layout' => 'checkout', 'status' => 'reserved']);
    $process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id,
        'name' => 'queue', 'runtime' => 'systemd', 'runtime_config' => ['command' => 'php artisan queue:work'], 'status' => 'active']);
    $schedule = Schedule::query()->create(['target_type' => Instance::MorphAlias, 'target_id' => $this->instance->id,
        'host_node_id' => $this->node->id, 'name' => 'scheduler', 'calendar' => 'minutely', 'command' => 'php artisan schedule:run',
        'timeout_seconds' => 60, 'desired_timer_state' => 'enabled', 'status' => 'active']);
    $database = DatabaseConnection::query()->create(['slug' => 'removed-sqlite', 'driver' => 'sqlite', 'owner_instance_id' => $this->instance->id,
        'node_id' => $this->node->id, 'path' => $this->instance->checkout_path.'/database.sqlite']);
    $routes = [];
    foreach ([null, 'docs/public'] as $index => $webRoot) {
        $route = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
            'domain' => 'projected-'.$index.'.test', 'web_root' => $webRoot, 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
        $routes[] = $route;
    }
    $input = directDestroyFixture($this->instance);
    $input['members'][0]['route_id'] = $routes[0]->id;
    $input['additional_route_ids'] = [$routes[1]->id];
    $intent = app(DirectInstanceDestroyIntent::class)->record($input);
    $result = directDestroyResult($intent, [$database->id]);

    $receipt = app(ProjectDirectInstanceDestroyAction::class)->execute($intent, $result);
    $replayed = app(ProjectDirectInstanceDestroyAction::class)->execute($intent, $result);

    expect($receipt->status->value)->toBe('completed')
        ->and($replayed->id)->toBe($receipt->id)
        ->and($receipt->members->sole()->route_ids)->toBe(array_column($routes, 'id'))
        ->and($receipt->members->sole()->row_deleted_at)->not->toBeNull();
    foreach ([$this->instance, $database, $process, $schedule, ...$routes] as $removed) {
        $this->assertModelMissing($removed);
    }
    $this->assertModelExists($neighbor);
    expect(Operation::query()->count())->toBe(0);
});

it('keeps removal records intact when the native result is incomplete or belongs elsewhere', function (string $change): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $result = directDestroyResult($intent);
    match ($change) {
        'member' => $result['instance_ids'] = [$this->instance->id + 100],
        'removal' => $result['removal_id'] = (string) Str::uuid(),
        'databases' => $result['database_results_sha256'] = str_repeat('0', 64),
        'private' => $result['private'] = 'not-an-admitted-result',
    };

    expect(fn () => app(ProjectDirectInstanceDestroyAction::class)->execute($intent, $result))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.result_invalid'));

    $this->assertModelExists($this->instance);
    $member = InstanceRemoval::query()->findOrFail($intent['removal_id'])->members()->sole();
    expect($member->source_prepared_at)->toBeNull()->and($member->row_deleted_at)->toBeNull();
})->with(['member', 'removal', 'databases', 'private']);

it('refuses newly owned runtime before projecting any native removal records', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $database = DatabaseConnection::query()->create(['slug' => 'retained-sqlite', 'driver' => 'sqlite', 'owner_instance_id' => $this->instance->id,
        'node_id' => $this->node->id, 'path' => $this->instance->checkout_path.'/database.sqlite']);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $late = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id,
        'name' => 'late', 'runtime' => 'systemd', 'runtime_config' => ['command' => 'echo late'], 'status' => 'active']);

    expect(fn () => app(ProjectDirectInstanceDestroyAction::class)->execute($intent, directDestroyResult($intent, [$database->id])))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.removal_conflict'));

    foreach ([$this->instance, $database, $late] as $retained) {
        $this->assertModelExists($retained);
    }
    expect(InstanceRemoval::query()->findOrFail($intent['removal_id'])->members()->sole()->source_prepared_at)->toBeNull();
});

it('refuses an incomplete owning database artifact set before projecting removal', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $database = DatabaseConnection::query()->create(['slug' => 'omitted-sqlite', 'driver' => 'sqlite', 'owner_instance_id' => $this->instance->id,
        'node_id' => $this->node->id, 'path' => $this->instance->checkout_path.'/database.sqlite']);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $result = directDestroyResult($intent, [$database->id]);
    $intent['direct_destroy']['databases'] = [];
    $result['database_results_sha256'] = hash('sha256', '[]');

    expect(fn () => app(ProjectDirectInstanceDestroyAction::class)->execute($intent, $result))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.result_invalid'));

    $this->assertModelExists($this->instance);
    $this->assertModelExists($database);
});

it('refuses another source journal or finalization receipt during record projection', function (string $field): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    if ($field === 'receipt') {
        $intent['direct_destroy']['members'][0]['receipt'] = str_repeat('a', 64);
    } else {
        $intent['direct_destroy']['members'][0]['source'][$field] = $field === 'member' ? '9999999' : (string) Str::uuid();
    }

    expect(fn () => app(ProjectDirectInstanceDestroyAction::class)->execute($intent, directDestroyResult($intent)))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.removal_conflict'));

    $this->assertModelExists($this->instance);
    expect(InstanceRemoval::query()->findOrFail($intent['removal_id'])->members()->sole()->source_prepared_at)->toBeNull();
})->with(['operation', 'member', 'receipt']);

it('rolls back native removal projection when the owning parent transaction fails', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $database = DatabaseConnection::query()->create(['slug' => 'parent-rollback-sqlite', 'driver' => 'sqlite', 'owner_instance_id' => $this->instance->id,
        'node_id' => $this->node->id, 'path' => $this->instance->checkout_path.'/database.sqlite']);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));

    expect(fn () => DB::transaction(function () use ($intent, $database): void {
        app(ProjectDirectInstanceDestroyAction::class)->execute($intent, directDestroyResult($intent, [$database->id]));
        throw new RuntimeException('The owning Task projection failed.');
    }))->toThrow(RuntimeException::class, 'The owning Task projection failed.');

    $this->assertModelExists($this->instance);
    $this->assertModelExists($database);
    $member = InstanceRemoval::query()->findOrFail($intent['removal_id'])->members()->sole();
    expect($member->source_prepared_at)->toBeNull()->and($member->row_deleted_at)->toBeNull();
});

it('keeps the complete admitted Route inventory immutable before successful projection', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $route = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'domain' => 'guarded-removal.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $input = directDestroyFixture($this->instance);
    $input['members'][0]['route_id'] = $route->id;
    $intent = app(DirectInstanceDestroyIntent::class)->record($input);
    $member = InstanceRemoval::query()->findOrFail($intent['removal_id'])->members()->sole();

    expect(fn () => $route->delete())->toThrow(QueryException::class);
    expect(fn () => $member->update(['route_ids' => []]))->toThrow(QueryException::class);

    $this->assertModelExists($route);
    expect($member->refresh()->route_ids)->toBe([$route->id])->and($member->source_prepared_at)->toBeNull();
});

it('records canonical destruction inside its actual Task parent without a child', function (string $kind, string $policy, bool $attached): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'End owned workspace',
        'brief' => 'Remove this owned checkout.', 'status' => TaskGroupStatus::Running]);
    $this->instance = test_task_workspace($group, ['node_id' => $this->node->id, 'status' => 'reserved',
        'source_prepare_id' => (string) Str::uuid(), 'checkout_path' => '/srv/apps/destroy-proof/task-cleanup'], $attached);

    $intent = app(TaskGroupEndingIntent::class)->record($group, $kind, $policy, directDestroyFixture($this->instance));

    expect($intent['group_id'])->toBe($group->id)
        ->and($intent['project_id'])->toBe($this->project->id)
        ->and($intent['completion_policy'])->toBe($policy)
        ->and($intent['workspace'])->toBe(['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'task_vm_id' => null])
        ->and($intent['destruction']['direct_destroy']['members'][0]['instance_id'])->toBe($this->instance->id)
        ->and($intent['destruction']['direct_destroy']['node_user'])->toBe($this->node->user)
        ->and($this->instance->refresh()->status->value)->toBe('removing')
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($group->taskable_id)->toBe($attached ? $this->instance->id : null)
        ->and(Operation::query()->count())->toBe(0)
        ->and(InstanceRemoval::query()->count())->toBe(1);
})->with([['tasks.cancel', 'cancel', true], ['tasks.complete', 'manual', true], ['tasks.complete', 'settle', false]]);

it('records genuinely absent Task workspaces without inventing destruction', function (): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'No workspace',
        'brief' => 'End a group with no admitted workspace.', 'status' => TaskGroupStatus::Todo]);

    expect(app(TaskGroupEndingIntent::class)->record($group, 'tasks.cancel', 'cancel', null))->toBe([
        'group_id' => $group->id, 'project_id' => $this->project->id, 'completion_policy' => 'cancel',
        'workspace' => null, 'destruction' => null, 'preservation' => null,
    ])->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and(InstanceRemoval::query()->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(0);
});

it('refuses inconsistent Task ending policy before recording removal', function (string $kind, string $policy): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Wrong policy',
        'brief' => 'Keep the workspace.', 'status' => TaskGroupStatus::Running]);
    $this->instance = test_task_workspace($group, ['node_id' => $this->node->id, 'status' => 'reserved',
        'source_prepare_id' => (string) Str::uuid(), 'checkout_path' => '/srv/apps/destroy-proof/task-cleanup']);

    expect(fn () => app(TaskGroupEndingIntent::class)->record($group, $kind, $policy, directDestroyFixture($this->instance)))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tasks.workspace_mismatch'));
    expect(InstanceRemoval::query()->count())->toBe(0)
        ->and($this->instance->refresh()->status->value)->toBe('reserved');
})->with([['tasks.cancel', 'manual'], ['tasks.cancel', 'settle'], ['tasks.complete', 'cancel'], ['instance.destroy', 'cancel']]);

it('rejects foreign or changed Task destruction identities without marking Removing', function (string $change): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Owned workspace',
        'brief' => 'Keep other source.', 'status' => TaskGroupStatus::Running]);
    if ($change !== 'unowned') {
        $this->instance = test_task_workspace($group, ['node_id' => $this->node->id, 'status' => 'reserved',
            'source_prepare_id' => (string) Str::uuid(), 'checkout_path' => '/srv/apps/destroy-proof/task-cleanup']);
    }
    $snapshot = directDestroyFixture($this->instance);
    match ($change) {
        'unowned' => null,
        'instance' => $snapshot['instance_id']++,
        'project' => $snapshot['project_id']++,
        'node' => $snapshot['node_id']++,
        'force' => $snapshot['force'] = false,
        'member' => $snapshot['members'][0]['instance_id']++,
        'cascade' => $snapshot['members'][] = [...$snapshot['members'][0], 'instance_id' => 999999],
        'environment' => $snapshot['members'][0]['environment'] = 'production',
    };

    expect(fn () => app(TaskGroupEndingIntent::class)->record($group, 'tasks.cancel', 'cancel', $snapshot))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('tasks.workspace_mismatch'));
    expect(InstanceRemoval::query()->count())->toBe(0)
        ->and($this->instance->refresh()->status->value)->toBe('reserved')
        ->and(Operation::query()->count())->toBe(0);
})->with(['unowned', 'instance', 'project', 'node', 'force', 'member', 'cascade', 'environment']);

it('projects Task-owned destruction atomically through the actual Task root', function (): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel');
    $result = taskEndingResult($parent);

    $projected = app(TaskGroupEndingProjection::class)->execute($parent, $result);

    expect($projected->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($projected->taskable_id)->toBeNull()
        ->and($parent->kind)->toBe('tasks.cancel')
        ->and(Operation::query()->count())->toBe(0);
    $this->assertModelMissing($workspace);
    $this->assertModelExists($this->instance);
    expect(InstanceRemoval::query()->sole()->status->value)->toBe('completed');

    expect(app(TaskGroupEndingProjection::class)->execute($parent, $result)->status)->toBe(TaskGroupStatus::Cancelled);
});

it('keeps retained Task workspaces attached under the admitted completion policy', function (string $policy, string $status): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.complete', $policy);
    $result = ['group_id' => $group->id, 'group_status' => $status, 'workspace_outcome' => 'retained',
        'destruction' => null, 'cleanup_error_code' => 'instance.remove_refused', 'preservation' => null];

    $projected = app(TaskGroupEndingProjection::class)->execute($parent, $result);

    expect($projected->status)->toBe($policy === 'manual' ? TaskGroupStatus::Completed : TaskGroupStatus::Running)
        ->and($projected->taskable_id)->toBe($workspace->id)
        ->and($projected->assistance_kind->value)->toBe('removal_failure')
        ->and($workspace->refresh()->status->value)->toBe('removing')
        ->and(InstanceRemoval::query()->sole()->status->value)->toBe('removing');
})->with([['manual', 'completed'], ['settle', 'unchanged']]);

it('refuses unproven Task endings before deleting any workspace or association', function (string $change): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.complete', 'settle');
    $result = taskEndingResult($parent);
    match ($change) {
        'parent-kind' => $parent->kind = 'instance.destroy',
        'parent-target' => $parent->target_id++,
        'parent-status' => $parent->status = 'failed',
        'result-group' => $result['group_id']++,
        'result-policy' => $result['group_status'] = 'cancelled',
        'result-absent' => $result['workspace_outcome'] = 'absent',
        'partial' => $result['destruction']['instance_ids'] = [],
        'extra' => $result['unexpected'] = true,
    };

    expect(fn () => app(TaskGroupEndingProjection::class)->execute($parent, $result))->toThrow(ResourceOperationException::class);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($group->taskable_id)->toBe($workspace->id)
        ->and($workspace->refresh()->status->value)->toBe('removing')
        ->and(InstanceRemoval::query()->sole()->status->value)->toBe('removing');
})->with(['parent-kind', 'parent-target', 'parent-status', 'result-group', 'result-policy', 'result-absent', 'partial', 'extra']);

it('refuses to project a late attached workspace as absent', function (): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Late workspace',
        'brief' => 'Keep late source.', 'status' => TaskGroupStatus::Running]);
    $input = app(TaskGroupEndingIntent::class)->record($group, 'tasks.cancel', 'cancel', null);
    $parent = new Operation(['kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => $group->id,
        'status' => 'succeeded', 'input' => $input]);
    $workspace = test_task_workspace($group, ['node_id' => $this->node->id, 'status' => 'reserved']);
    $result = ['group_id' => $group->id, 'group_status' => 'cancelled', 'workspace_outcome' => 'absent',
        'destruction' => null, 'cleanup_error_code' => null, 'preservation' => null];

    expect(fn () => app(TaskGroupEndingProjection::class)->execute($parent, $result))->toThrow(ResourceOperationException::class);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->taskable_id)->toBe($workspace->id);
    $this->assertModelExists($workspace);
});

it('projects an absent Task workspace only when it is still absent', function (): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Nothing to remove',
        'brief' => 'End a group without a workspace.', 'status' => TaskGroupStatus::Running]);
    $input = app(TaskGroupEndingIntent::class)->record($group, 'tasks.cancel', 'cancel', null);
    $parent = new Operation(['kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => $group->id,
        'status' => 'succeeded', 'input' => $input]);
    $result = ['group_id' => $group->id, 'group_status' => 'cancelled', 'workspace_outcome' => 'absent',
        'destruction' => null, 'cleanup_error_code' => null, 'preservation' => null];

    expect(app(TaskGroupEndingProjection::class)->execute($parent, $result)->status)->toBe(TaskGroupStatus::Cancelled)
        ->and(InstanceRemoval::query()->count())->toBe(0);
    $this->assertModelExists($this->instance);
});

it('does not treat local source proof as Task VM deletion proof', function (): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel');
    $input = $parent->input;
    $input['workspace']['task_vm_id'] = 42;
    $parent->input = $input;

    expect(fn () => app(TaskGroupEndingProjection::class)->execute($parent, taskEndingResult($parent)))
        ->toThrow(ResourceOperationException::class);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->taskable_id)->toBe($workspace->id)
        ->and(InstanceRemoval::query()->sole()->status->value)->toBe('removing');
    $this->assertModelExists($workspace);
});

it('hydrates canonical Git and teardown only inside the actual Task parent scope', function (string $kind, string $policy): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, $kind, $policy, true);
    $parent->status = 'running';
    $this->project->update(['source_access' => 'gh_cli']);
    $token = 'ghp_task_destroy_private_value_893442781';
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldReceive('token')->once()->andReturn($token);
    app()->instance(GitHubCliToken::class, $cli);
    $input = $parent->input['destruction'];

    $material = app(OperationSecrets::class)->hydrate($parent, '', ['destruction' => [
        'git_read' => $input['git_read'], 'teardown' => $input['teardown'],
    ]], ['destruction.git_read' => 'instance.lifecycle_git', 'destruction.teardown' => 'instance.destroy_teardown']);

    expect($material['input']['destruction']['git_read'])->toBeInstanceOf(GitReadEnvironment::class)
        ->and($material['input']['destruction']['git_read']->githubToken())->toBe($token)
        ->and($material['input']['destruction']['teardown']['members'][0]['instance_id'])->toBe($workspace->id)
        ->and($material['input']['destruction']['teardown']['members'][0]['steps'][0]['command'])->toBe($this->command)
        ->and($parent->kind)->toBe($kind)->and($parent->target_id)->toBe($group->id)
        ->and(json_encode($parent->input))->not->toContain($token)->not->toContain($this->command)
        ->and(Operation::query()->count())->toBe(0);
    expect(app(OperationSecrets::class)->redact(['git' => $token, 'script' => $this->command], $material['values']))
        ->toBe(['git' => '[REDACTED]', 'script' => '[REDACTED]']);
})->with([['tasks.cancel', 'cancel'], ['tasks.complete', 'manual']]);

it('refuses Task material after its exact ownership or admitted Node changes', function (string $change): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel', true);
    $input = $parent->input;
    match ($change) {
        'kind' => $parent->kind = 'tasks.handoff',
        'target' => $parent->target_id++,
        'parent-node' => $parent->node_id++,
        'group' => $input['group_id']++,
        'project' => $input['project_id']++,
        'workspace' => $input['workspace']['instance_id']++,
        'workspace-node' => $input['workspace']['node_id']++,
        'destruction-project' => $input['destruction']['project_id']++,
        'destruction-node' => $input['destruction']['node_id']++,
        'member' => $input['destruction']['members'][0]['instance_id']++,
        'policy' => $input['completion_policy'] = 'manual',
    };
    $parent->input = $input;
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldNotReceive('token');
    app()->instance(GitHubCliToken::class, $cli);

    expect(app(InstanceLifecycleGitSecret::class)->ownerId($parent))->toBeNull()
        ->and(app(InstanceDestroyTeardownSecret::class)->ownerId($parent))->toBeNull();
    expect(fn () => app(InstanceLifecycleGitSecret::class)->resolve($parent, $this->project->id, '', 'destruction.git_read'))
        ->toThrow(ResourceOperationException::class);
    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($parent, $workspace->id, '', 'destruction.teardown'))
        ->toThrow(ResourceOperationException::class);
})->with(['kind', 'target', 'parent-node', 'group', 'project', 'workspace', 'workspace-node', 'destruction-project', 'destruction-node', 'member', 'policy']);

it('refuses top-level or child-key material requests from a Task parent', function (string $key, string $git, string $teardown): void {
    [, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel', true);
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldNotReceive('token');
    app()->instance(GitHubCliToken::class, $cli);

    expect(fn () => app(InstanceLifecycleGitSecret::class)->resolve($parent, $this->project->id, $key, $git))
        ->toThrow(ResourceOperationException::class);
    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($parent, $workspace->id, $key, $teardown))
        ->toThrow(ResourceOperationException::class);
})->with([['', 'git_read', 'teardown'], ['child-cleanup', 'destruction.git_read', 'destruction.teardown']]);

it('refuses a Task kind disguised as an Instance-root material request', function (): void {
    [, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel', true);
    $parent->target_type = 'instance';
    $parent->target_id = $workspace->id;
    $parent->input = $parent->input['destruction'];
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldNotReceive('token');
    app()->instance(GitHubCliToken::class, $cli);

    expect(app(InstanceLifecycleGitSecret::class)->ownerId($parent))->toBeNull()
        ->and(app(InstanceDestroyTeardownSecret::class)->ownerId($parent))->toBeNull();
    expect(fn () => app(InstanceLifecycleGitSecret::class)->resolve($parent, $this->project->id, '', 'git_read'))
        ->toThrow(ResourceOperationException::class);
    expect(fn () => app(InstanceDestroyTeardownSecret::class)->resolve($parent, $workspace->id, '', 'teardown'))
        ->toThrow(ResourceOperationException::class);
});

/** @return array{Task, Instance, Operation} */
function taskEndingFixture(Instance $example, string $kind, string $policy, bool $withTeardown = false): array
{
    $group = Task::topLevel()->create(['project_id' => $example->project_id, 'title' => 'Project owned cleanup',
        'brief' => 'Remove exactly its owned workspace.', 'status' => TaskGroupStatus::Running]);
    $workspace = test_task_workspace($group, ['node_id' => $example->node_id, 'status' => 'reserved',
        'source_prepare_id' => (string) Str::uuid(), 'checkout_path' => '/srv/apps/destroy-proof/task-cleanup']);
    $snapshot = directDestroyFixture($workspace);
    $snapshot['members'][0]['run_teardown'] = $withTeardown;
    $input = app(TaskGroupEndingIntent::class)->record($group, $kind, $policy, $snapshot);

    return [$group, $workspace, new Operation(['kind' => $kind, 'target_type' => 'task_group',
        'target_id' => $group->id, 'node_id' => $workspace->node_id, 'status' => 'succeeded', 'input' => $input])];
}

/** @return array<string, mixed> */
function taskEndingResult(Operation $parent): array
{
    return ['group_id' => $parent->target_id, 'group_status' => $parent->kind === 'tasks.cancel' ? 'cancelled' : 'completed',
        'workspace_outcome' => 'removed', 'destruction' => directDestroyResult($parent->input['destruction']), 'cleanup_error_code' => null, 'preservation' => null];
}

/** @param array<string,mixed> $intent
 * @param  list<int>  $databaseIds
 * @return array<string,mixed>
 */
function directDestroyResult(array $intent, array $databaseIds = []): array
{
    $results = array_map(static fn (array $artifact): array => [
        'artifact_id' => $artifact['artifact_id'], 'owner_instance_id' => $artifact['owner_instance_id'],
        'node_id' => $artifact['node_id'], 'removed_connection_ids' => array_column($artifact['connections'], 'connection_id'), 'backend' => 'absent',
    ], $intent['direct_destroy']['databases']);

    return ['removal_id' => $intent['removal_id'], 'removed' => true,
        'instance_ids' => array_column($intent['direct_destroy']['members'], 'instance_id'),
        'database_results_sha256' => hash('sha256', json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
}

function directDestroyFixture(Instance $instance): array
{
    return ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'project_id' => $instance->project_id,
        'name' => $instance->name, 'force' => true, 'removal_id' => (string) Str::uuid(), 'inventory_digest' => str_repeat('b', 64),
        'repository' => $instance->project->repository_url, 'repository_identity' => $instance->project->repository_identity,
        'additional_route_ids' => [], 'additional_routes' => [], 'members' => [[
            'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'project_id' => $instance->project_id,
            'position' => 0, 'environment' => 'development', 'name' => $instance->name,
            'route_id' => null, 'source_layout' => $instance->source_layout,
            'repository_identity' => $instance->project->repository_identity,
            'checkout_path' => $instance->checkout_path, 'root' => 'public', 'branch' => null,
            'starting_commit' => null, 'source_commit' => '', 'common_repository_path' => $instance->checkout_path,
            'source_identity' => 'absent', 'linked_worktree_paths' => [$instance->checkout_path],
            'source_digest' => str_repeat('c', 64), 'runtime_published' => false, 'source_root' => '/srv/apps',
            'user' => 'orbit', 'group' => 'orbit', 'run_teardown' => false,
            'worktree_inventory' => base64_encode('worktree '.$instance->checkout_path."\0\0"),
            'origin' => $instance->project->repository_url,
        ]]];
}

it('uses the fixed native Git wire for an actual Task private HTTP read', function (): void {
    [, , $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel', true);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $parent->status = 'running';
    $parent->state = ['task_ending_writer' => 'direct', 'swamp_run_id' => $run];
    $parent->save();
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'private-task-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    config(['operation_executor.worker_node_ids' => [], 'dependency_detection.services_node_id' => $gateway->id]);
    $declaration = new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['tasks.cancel' => ['workflow' => 'operation-task-group-ending', 'writer_config' => 'swamp.direct_writers',
                'writer_state' => 'task_ending_writer', 'projection' => DirectOperationResultProjection::class,
                'private_material' => ['destruction.git_read' => 'instance.lifecycle_git']]];
        }
    };
    app()->instance(DirectOperation::class, new DirectOperation([$declaration]));
    $this->project->update(['source_access' => 'gh_cli']);
    $token = 'ghp_synthetic_native_wire_probe_763182';
    $cli = Mockery::mock(GitHubCliToken::class);
    $cli->shouldReceive('token')->once()->andReturn($token);
    app()->instance(GitHubCliToken::class, $cli);

    $this->getJson('/api/v1/direct-operations/'.$parent->id.'/private-material?run_id='.$run)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.destruction.git_read', ['github_token' => $token]);
});

it('captures the exact unpublished approved commit and remote branch before Task removal', function (bool $incoming): void {
    $group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Keep approved work',
        'brief' => 'Admit exact preservation.', 'status' => TaskGroupStatus::Running]);
    $workspace = test_task_workspace($group, ['node_id' => $this->node->id, 'status' => 'reserved',
        'source_prepare_id' => (string) Str::uuid(), 'checkout_path' => '/srv/apps/destroy-proof/task-preservation']);
    $task = Task::query()->create(['parent_id' => $group->id, 'title' => 'Approved work', 'brief' => 'Preserve the approved commit.', 'status' => 'completed', 'position' => 0]);
    if ($incoming) {
        $group->update(['pr_branch' => 'incoming-reviewed-branch']);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('a', 40), 'source' => 'orbit_push']);
    } else {
        TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id,
            'type' => TaskCommentType::Approved, 'commit_sha' => str_repeat('a', 40), 'body' => 'Keep this approved commit.', 'author' => 'reviewer', 'posted_at' => now()]);
    }

    $intent = app(TaskGroupEndingIntent::class)->record($group, 'tasks.cancel', 'cancel', directDestroyFixture($workspace));

    expect($intent['preservation'])->toBe(['commit' => str_repeat('a', 40), 'branch' => $incoming ? 'incoming-reviewed-branch' : 'task-'.$group->id]);
    expect($task->refresh()->review_handled_comment_id)->toBeNull();
})->with(['approval' => false, 'reviewed' => true]);

it('rejects Task deletion without the exact admitted preservation proof', function (string $change): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel');
    $input = $parent->input;
    $input['preservation'] = ['commit' => str_repeat('a', 40), 'branch' => 'task-'.$group->id];
    $parent->input = $input;
    $result = taskEndingResult($parent);
    $result['preservation'] = ['commit' => str_repeat('a', 40), 'branch' => 'task-'.$group->id, 'pushed' => true];
    match ($change) {
        'missing' => $result['preservation'] = null,
        'commit' => $result['preservation']['commit'] = str_repeat('b', 40),
        'branch' => $result['preservation']['branch'] = 'unrelated',
        'unproved' => $result['preservation']['pushed'] = false,
        'extra' => $result['preservation']['extra'] = true,
    };

    expect(fn () => app(TaskGroupEndingProjection::class)->execute($parent, $result))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($workspace);
    expect($group->refresh()->taskable_id)->toBe($workspace->id)
        ->and(InstanceRemoval::query()->sole()->status->value)->toBe('removing');
})->with(['missing', 'commit', 'branch', 'unproved', 'extra']);

it('records proven Task preservation atomically with workspace deletion', function (): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel');
    $task = Task::query()->create(['parent_id' => $group->id, 'title' => 'Approved work', 'brief' => 'Preserve the approved commit.', 'status' => 'completed', 'position' => 0]);
    $approval = TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id,
        'type' => TaskCommentType::Approved, 'commit_sha' => str_repeat('a', 40), 'body' => 'Keep this approved commit.', 'author' => 'reviewer', 'posted_at' => now()]);
    $input = $parent->input;
    $input['preservation'] = ['commit' => str_repeat('a', 40), 'branch' => 'task-'.$group->id];
    $parent->input = $input;
    $result = taskEndingResult($parent);
    $result['preservation'] = [...$input['preservation'], 'pushed' => true];

    app(TaskGroupEndingProjection::class)->execute($parent, $result);

    expect($task->refresh()->review_handled_comment_id)->toBe($approval->id);
    $this->assertModelMissing($workspace);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('retains Task records when newer approved work was not in the admitted preservation', function (): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel');
    $task = Task::query()->create(['parent_id' => $group->id, 'title' => 'Approved work', 'brief' => 'Preserve the approved commit.', 'status' => 'completed', 'position' => 0]);
    TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id,
        'type' => TaskCommentType::Approved, 'commit_sha' => str_repeat('b', 40), 'body' => 'New approved work.', 'author' => 'reviewer', 'posted_at' => now()]);
    $result = taskEndingResult($parent);

    expect(fn () => app(TaskGroupEndingProjection::class)->execute($parent, $result))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($workspace);
    expect($task->refresh()->review_handled_comment_id)->toBeNull();
});

it('rolls back Task preservation checkpoints when canonical removal proof is rejected', function (): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.cancel', 'cancel');
    $task = Task::query()->create(['parent_id' => $group->id, 'title' => 'Approved work', 'brief' => 'Preserve this commit.', 'status' => 'completed', 'position' => 0]);
    TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id,
        'type' => TaskCommentType::Approved, 'commit_sha' => str_repeat('a', 40), 'body' => 'Approved.', 'author' => 'reviewer', 'posted_at' => now()]);
    $input = $parent->input;
    $input['preservation'] = ['commit' => str_repeat('a', 40), 'branch' => 'task-'.$group->id];
    $parent->input = $input;
    $result = taskEndingResult($parent);
    $result['preservation'] = [...$input['preservation'], 'pushed' => true];
    $result['destruction']['instance_ids'] = [];

    expect(fn () => app(TaskGroupEndingProjection::class)->execute($parent, $result))->toThrow(ResourceOperationException::class);

    expect($task->refresh()->review_handled_comment_id)->toBeNull();
    $this->assertModelExists($workspace);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
});

it('keeps a failed preservation attached under manual Task completion', function (): void {
    [$group, $workspace, $parent] = taskEndingFixture($this->instance, 'tasks.complete', 'manual');
    $input = $parent->input;
    $input['preservation'] = ['commit' => str_repeat('a', 40), 'branch' => 'task-'.$group->id];
    $parent->input = $input;
    $result = ['group_id' => $group->id, 'group_status' => 'completed', 'workspace_outcome' => 'retained',
        'destruction' => null, 'preservation' => null, 'cleanup_error_code' => 'tasks.push_failed'];

    app(TaskGroupEndingProjection::class)->execute($parent, $result);

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
        ->and($group->taskable_id)->toBe($workspace->id)
        ->and($group->assistance_kind->value)->toBe('removal_failure');
    $this->assertModelExists($workspace);
});

it('acknowledges actual successful destroy POST and terminal GET after owned rows are gone', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $run = (string) Str::uuid();
    $operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running',
        'input' => $intent, 'state' => ['instance_destroy_writer' => 'direct', 'swamp_run_id' => $run]]);
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'destroy-callback-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    config(['operation_executor.worker_node_ids' => [], 'dependency_detection.services_node_id' => $gateway->id]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $body = ['run_id' => $run, 'status' => 'succeeded', 'started_at' => '2026-10-31T04:00:00.000Z',
        'finished_at' => '2026-10-31T04:00:01.000Z', 'result' => directDestroyResult($intent), 'error_code' => null];
    $this->postJson($path.'/result', $body)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->assertModelMissing($this->instance);
    $this->getJson($path.'?run_id='.$run)->assertOk()->assertJsonPath('data.status', 'succeeded')
        ->assertJsonMissingPath('data.input.direct_destroy.routes.0.php');
    $this->postJson($path.'/result', $body)->assertOk()->assertJsonPath('data.status', 'succeeded');
    expect($operation->children()->count())->toBe(0);
});

it('returns a fresh same-root Caddy render and keeps PHP in the private channel', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $operation = new Operation(['kind' => 'instance.destroy', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running', 'input' => $intent]);
    $input = app(InstanceDestroyProjection::class)->input($operation);
    expect($input['caddy_publication']['node_id'])->toBe($this->node->id)
        ->and($input['direct_destroy']['routes'][0])->not->toHaveKey('php')
        ->and($input['withdrawal_publications'][0])->not->toHaveKey('php');
});

it('has exactly one direct destroy dispatch and no executor or child execution contract', function (): void {
    $kinds = app(OperationKinds::class);
    $definition = $kinds->definition('instance.destroy');
    expect($kinds->directOnly('instance.destroy'))->toBeTrue()
        ->and($definition)->not->toHaveKeys(['execution', 'runs', 'children', 'completion']);
    expect(app(DirectOperation::class)->writer('instance.destroy'))->toBe('direct');
    config(['swamp.direct_writers.instance.destroy' => 'polled']);
    expect(fn () => app(DirectOperation::class)->writer('instance.destroy'))->toThrow(ResourceOperationException::class);
});
