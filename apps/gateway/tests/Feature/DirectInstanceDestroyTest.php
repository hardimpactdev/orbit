<?php

declare(strict_types=1);

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\InstanceDestroyTeardownSecret;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Shared\ResourceOperationException;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Destroy proof', 'slug' => 'destroy-proof',
        'repository_url' => 'https://github.com/example/destroy-proof.git', 'root' => 'public']);
    $this->node = Node::query()->create(['name' => 'destroy-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
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

it('records managed database work without executing a child', function (string $driver): void {
    $server = DatabaseServer::query()->create(['slug' => 'managed-proof', 'node_id' => $this->node->id,
        'driver' => $driver, 'tag' => 'test', 'port' => 3306, 'root_password' => 'protected-server-password', 'status' => 'active']);
    $connection = DatabaseConnection::query()->create(['slug' => 'managed-proof', 'driver' => $driver,
        'database_server_id' => $server->id, 'owner_instance_id' => $this->instance->id]);

    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $recorded = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    expect($recorded['direct_destroy']['database']['connection_ids'])->toBe([$connection->id])
        ->and($recorded['direct_destroy']['database']['servers'][0]['server_id'])->toBe($server->id)
        ->and(json_encode($recorded))->not->toContain('protected-server-password')
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

    expect($recorded['direct_destroy']['database']['owner_instance_id'])->toBe($this->instance->id)
        ->and($recorded['direct_destroy']['database']['sqlite'])->toBe([[
            'connection_id' => $connection->id, 'node_id' => $this->node->id, 'checkout' => $this->instance->checkout_path, 'path' => $connection->path,
        ]])->and($recorded['direct_destroy']['runtime'])->toBe(['processes' => [], 'schedules' => []])
        ->and($recorded['direct_destroy']['node_user'])->toBe($this->node->user)
        ->and($recorded['direct_destroy']['teardown'][0]['user'])->toBe($this->node->user)
        ->and($recorded['direct_destroy']['teardown'][0]['steps'])->toBe([])
        ->and($recorded['members'][0]['worktree_inventory'])->toBe($input['members'][0]['worktree_inventory'])
        ->and($recorded['direct_destroy']['routes'])->toBe([])
        ->and($recorded['native_destroy']['route_mutations']['forward']['publications'])->toBe([])
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
        'database_server_id' => $server->id, 'database' => 'mysql', 'owner_instance_id' => $other->id]);
    $input = directDestroyFixture($this->instance);
    $input['members'][] = directDestroyFixture($other)['members'][0];

    expect(fn () => app(DirectInstanceDestroyIntent::class)->record($input))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.system_database'));

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
        ->and(array_column($recorded['direct_destroy']['database']['sqlite'], 'connection_id'))->toBe([$connection->id])
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

    $forward = $recorded['native_destroy']['route_mutations']['forward'];
    expect($forward['publications'])->not->toBeEmpty()
        ->and($recorded['direct_destroy']['publication_node_ids'])->toContain($this->node->id)
        ->and(json_encode($forward))->toContain('route-'.$routes[1]->id)
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

it('records immutable workload Router and VPN participants while retaining all Route associations', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $routers = [];
    foreach (['current', 'transition'] as $index => $name) {
        $cluster = Cluster::query()->create(['name' => $name]);
        $router = Node::query()->create(['name' => $name, 'cluster_id' => $cluster->id,
            'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'amd64', 'status' => 'active',
            'public_ssh_host' => '192.0.2.'.($index + 17), 'wireguard_ip' => '10.44.0.'.($index + 17), 'ssh_host_key_type' => $this->node->ssh_host_key_type, 'ssh_host_key' => $this->node->ssh_host_key]);
        $router->roles()->create(['role' => 'router', 'status' => 'active', 'cluster_id' => $cluster->id]);
        $routers[] = [$cluster, $router];
    }
    $this->node->update(['cluster_id' => $routers[0][0]->id]);
    $vpn = Node::query()->create(['name' => 'vpn', 'user' => 'orbit', 'platform' => 'linux',
        'architecture' => 'amd64', 'status' => 'active', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'ssh_host_key_type' => $this->node->ssh_host_key_type, 'ssh_host_key' => $this->node->ssh_host_key]);
    $vpn->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $this->project->id, 'cluster_id' => $routers[0][0]->id,
        'transition_cluster_id' => $routers[1][0]->id, 'domain' => 'cluster-destroy.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending', 'sites_published' => false]);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $input = directDestroyFixture($this->instance);
    $input['members'][0]['route_id'] = $route->id;

    $recorded = app(DirectInstanceDestroyIntent::class)->record($input);
    $participants = [$this->node->id, $routers[0][1]->id, $routers[1][1]->id, $vpn->id, 209];
    sort($participants);
    expect($recorded['participant_node_ids'])->toBe($participants)
        ->and($recorded['direct_destroy']['publication_node_ids'])->toBe($participants)
        ->and($this->instance->refresh()->status->value)->toBe('removing')
        ->and($route->refresh()->status->value)->toBe('retiring')
        ->and($route->sites_published)->toBeFalse()
        ->and(InstanceRemoval::query()->count())->toBe(1)
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id]);
});

it('captures public Route withdrawal using the canonical native mutation policy', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $route = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'domain' => 'public-destroy.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $cluster = Cluster::query()->create(['name' => 'public-scope']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $route->update(['node_id' => null, 'cluster_id' => $cluster->id, 'publication' => 'public']);
    $input = directDestroyFixture($this->instance);
    $input['members'][0]['route_id'] = $route->id;

    $recorded = app(DirectInstanceDestroyIntent::class)->record($input);
    expect($route->refresh()->status->value)->toBe('retiring')
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id])
        ->and($this->instance->refresh()->status->value)->toBe('removing')
        ->and($recorded['native_destroy']['route_mutations'])->toHaveKeys(['forward', 'restore']);
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

it('projects the immutable admitted set after bound native success without an evidence receipt', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $removal = app(ProjectDirectInstanceDestroyAction::class)->execute($intent, []);
    expect($removal->status->value)->toBe('completed');
    $this->assertModelMissing($this->instance);
});

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

function directDestroyResult(array $intent, array $databaseIds = []): array
{
    return ['removal_id' => $intent['removal_id'], 'removed' => true,
        'instance_ids' => array_column($intent['members'], 'instance_id')];
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

it('rejects HTTP result callbacks and projects only the bound native terminal once', function (): void {
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
    $this->postJson($path.'/result', $body)->assertUnprocessable();
    $projector = app(DevelopmentInstanceDestroyProjection::class);
    $projector->apply($operation, $body);
    $this->assertModelMissing($this->instance);
    expect($operation->refresh()->status->value)->toBe('succeeded');
    $projector->apply($operation, $body);
    expect($operation->refresh()->status->value)->toBe('succeeded');
});

it('keeps the admitted native publication snapshots stable after later Node changes', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $captured = $intent['native_destroy'];
    $this->node->update(['name' => 'later-name']);
    expect($intent['native_destroy'])->toBe($captured)
        ->and($captured['ssh_execution']['target']['host_keys'])->not->toBeEmpty()
        ->and($captured)->toHaveKeys(['route_mutations', 'execution_secrets']);
});

it('has exactly one direct destroy dispatch and no executor or child execution contract', function (): void {
    $kinds = app(OperationKinds::class);
    $definition = $kinds->definition('instance.destroy');
    expect($kinds->directOnly('instance.destroy'))->toBeTrue()
        ->and($definition)->not->toHaveKeys(['execution', 'runs', 'children', 'completion']);
    expect(app(DirectOperation::class)->writer('instance.destroy'))->toBe('direct');
    config(['swamp.direct_writers' => ['instance.destroy' => 'polled']]);
    expect(app(DirectOperation::class)->writer('instance.destroy'))->toBe('direct');
});

it('replays the saved admission after failure without recapturing database artifacts or source facts', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $initial = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $removal = InstanceRemoval::query()->sole();
    $removal->update(['status' => 'failed', 'failed_step' => 'source_preparation', 'error_code' => 'instance.destroy_failed']);
    $this->project->update(['repository_url' => 'https://github.com/example/changed.git']);

    $snapshot = app(StartInstanceDestroyAction::class)->snapshot($this->instance->refresh(), true);
    $retried = app(DirectInstanceDestroyIntent::class)->record($snapshot);

    expect($retried)->toEqual($initial);
    expect($removal->members()->sole()->origin)->toBe($initial['members'][0]['origin']);
    expect($removal->members()->sole()->worktree_inventory)->toBe($initial['members'][0]['worktree_inventory']);
    expect($removal->refresh()->status->value)->toBe('removing');
});

it('records native cancellation and leaves accepted removal retryable without restoring active intent', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $input = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $run = (string) Str::uuid();
    $operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'queued',
        'input' => $input, 'state' => ['swamp_run_id' => $run]]);

    app(CancelOperationAction::class)->execute($operation);
    expect($operation->refresh()->status->value)->toBe('queued')
        ->and($operation->cancel_requested_at)->not->toBeNull()
        ->and($this->instance->refresh()->status->value)->toBe('removing');
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'cancelled',
        'started_at' => '2026-10-31T04:00:00Z', 'finished_at' => '2026-10-31T04:00:01Z',
        'result' => ['error_code' => 'operation.cancelled', 'failed_step' => 'runtime'], 'error_code' => 'operation.cancelled']);
    expect($operation->refresh()->status->value)->toBe('cancelled')
        ->and($this->instance->refresh()->status->value)->toBe('removing');
    $retry = app(StartInstanceDestroyAction::class)->snapshot($this->instance, true);
    expect(app(DirectInstanceDestroyIntent::class)->record($retry))->toEqual($input);
});

it('projects native failure with its exact failed phase and retries the complete saved admission', function (): void {
    $this->instance->update(['source_prepare_id' => (string) Str::uuid()]);
    $intent = app(DirectInstanceDestroyIntent::class)->record(directDestroyFixture($this->instance));
    $run = (string) Str::uuid();
    $operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running',
        'input' => $intent, 'state' => ['instance_destroy_writer' => 'direct', 'swamp_run_id' => $run]]);
    $result = ['error_code' => 'instance.destroy_failed', 'failed_step' => 'databases'];

    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed',
        'started_at' => '2026-10-31T04:00:00.000Z', 'finished_at' => '2026-10-31T04:00:01.000Z',
        'result' => $result, 'error_code' => 'instance.destroy_failed']);

    expect($operation->refresh()->status->value)->toBe('failed');
    expect($operation->result)->toBe($result);
    expect(InstanceRemoval::query()->sole()->failed_step->value)->toBe('runtime_cleanup');
    $snapshot = app(StartInstanceDestroyAction::class)->snapshot($this->instance->refresh(), true);
    expect(app(DirectInstanceDestroyIntent::class)->record($snapshot))->toEqual($intent);
    $this->assertModelExists($this->instance);
});

it('upgrades a saved standalone worktree retry to independent forced removal', function (): void {
    $this->instance->update(['source_layout' => 'worktree', 'task_workspace_routed' => false]);
    $snapshot = directDestroyFixture($this->instance);
    $snapshot['force'] = false;
    $initial = app(DirectInstanceDestroyIntent::class)->record($snapshot);
    expect($initial['force'])->toBeFalse();
    $removal = InstanceRemoval::query()->sole();
    $removal->update(['status' => 'failed', 'failed_step' => 'source_preparation', 'error_code' => 'instance.destroy_failed']);

    $retry = app(StartInstanceDestroyAction::class)->snapshot($this->instance->refresh(), true);
    $recorded = app(DirectInstanceDestroyIntent::class)->record($retry);

    expect($recorded['force'])->toBeTrue();
    expect($recorded['members'][0]['worktree_inventory'])->toBe($initial['members'][0]['worktree_inventory']);
    expect($recorded['direct_destroy']['database'])->toBe($initial['direct_destroy']['database']);
});
