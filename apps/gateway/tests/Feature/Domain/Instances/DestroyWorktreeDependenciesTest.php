<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceDestroyWorktrees;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as LocalProcess;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->root = sys_get_temp_dir().'/orbit-destroy-admission-'.bin2hex(random_bytes(8));
    mkdir($this->root);
    $git = new LocalProcess(['git', 'init', '-q', $this->root.'/owner']);
    $git->mustRun();
    file_put_contents($this->root.'/owner/work', 'preserve both trees');
    new LocalProcess(['git', '-C', $this->root.'/owner', 'add', '.'])->mustRun();
    new LocalProcess(['git', '-C', $this->root.'/owner', '-c', 'user.name=Fixture', '-c', 'user.email=f@example.test', 'commit', '-qm', 'fixture'])->mustRun();
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'add', '-q', '--detach', $this->root.'/dependent'])->mustRun();
    $this->reader = new class implements RemoteProgramRunner
    {
        public int $calls = 0;

        public ?RemoteProgramFailure $failure = null;

        public function run(Node $node, RemoteProgram $program, array $input = [], ?GitReadEnvironment $gitRead = null, ?float $timeoutSeconds = null, ?Closure $cancelled = null): ProgramResult
        {
            $this->calls++;
            if ($this->failure !== null) {
                throw new RemoteProgramFailed($program->name, $this->failure);
            }
            $process = new LocalProcess(['python3', '-c', $program->source]);
            $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            return new ProgramResult($result['outcome'], $result['data'], $process->getErrorOutput());
        }
    };
    app()->instance(RemoteProgramRunner::class, $this->reader);
    $this->node = containerDefaultNode();
    $project = containerDefaultProject();
    $this->owner = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'owner',
        'checkout_path' => $this->root.'/owner', 'status' => 'active', 'source_layout' => 'checkout', 'root' => '.']);
    $other = Project::query()->create(['name' => 'Other Project', 'slug' => 'other', 'type' => 'node-package', 'repository_url' => 'https://github.com/example/other.git', 'default_branch' => 'main']);
    $this->dependent = Instance::query()->create(['project_id' => $other->id, 'node_id' => $this->node->id, 'name' => 'default',
        'checkout_path' => $this->root.'/dependent', 'status' => 'active', 'source_layout' => 'checkout', 'root' => '.']);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

it('refuses before any Operation write across Projects even with force and a lying layout record', function (bool $force): void {
    $schedule = Schedule::query()->create([
        'target_type' => 'instance', 'target_id' => $this->owner->id, 'host_node_id' => $this->node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'swamp',
        'swamp_workflow_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    ]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $this->owner->project_id, 'node_id' => $this->node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'owner.fixture.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->owner->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    $process = Process::query()->create(['owner_type' => 'instance', 'owner_id' => $this->owner->id, 'name' => 'worker',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/true']], 'desired_state' => 'running', 'status' => 'active']);
    $database = DatabaseConnection::query()->create(['slug' => 'owner-database', 'driver' => 'mysql', 'host' => '10.44.0.9',
        'port' => 3306, 'database' => 'owner', 'username' => 'owner', 'password' => 'fixture']);
    $database->targets()->create(['instance_id' => $this->owner->id, 'prefix' => 'DB', 'network' => 'wireguard']);
    $records = [$route, $process, $database, $this->owner];
    $beforeRecords = array_map(fn ($record) => $record->fresh()->getAttributes(), $records);
    $scheduleBefore = $schedule->fresh()->getAttributes();
    $before = Operation::query()->count();
    try {
        app(StartInstanceDestroyAction::class)->execute($this->owner, $force);
        test()->fail('The owner was admitted');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.worktree_dependents')->and($exception->status)->toBe(409)
            ->and($exception->details)->toBe(['dependents' => [['id' => $this->dependent->id, 'name' => 'default', 'project_id' => $this->dependent->project_id]],
                'other_dependents' => 0, 'unowned_paths' => [], 'dependent_paths' => [$this->dependent->checkout_path]]);
    }
    expect($schedule->fresh()->getAttributes())->toBe($scheduleBefore);
    expect(array_map(fn ($record) => $record->fresh()->getAttributes(), $records))->toBe($beforeRecords);
    expect(Operation::query()->count())->toBe($before)->and(InstanceRemoval::query()->count())->toBe(0)
        ->and($this->owner->fresh()->status->value)->toBe('active')
        ->and(is_dir($this->root.'/owner/.git'))->toBeTrue()->and(is_file($this->root.'/dependent/.git'))->toBeTrue();
})->with([false, true]);

it('returns HTTP 409 with visible dependents and no Operation side effects', function (): void {
    $this->node->accessibleNodes()->syncWithoutDetaching([$this->node->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])
        ->deleteJson('/api/v1/instances/'.$this->owner->id, ['force' => true])
        ->assertConflict()->assertJsonPath('error.code', 'instance.worktree_dependents')
        ->assertJsonPath('error.details.dependents.0.id', $this->dependent->id);
    expect(Operation::query()->count())->toBe(0)->and(InstanceRemoval::query()->count())->toBe(0);
});

it('reports unowned worktree paths and hides inaccessible dependent identities', function (): void {
    $details = app(InstanceDestroyWorktrees::class)->details($this->owner,
        [['path' => '/unowned', 'instance_ids' => []], ['path' => $this->dependent->checkout_path, 'instance_ids' => []]], 'node:999999');
    expect($details)->toBe(['dependents' => [], 'other_dependents' => 1, 'unowned_paths' => ['/unowned'], 'dependent_paths' => ['/unowned']]);
});

it('skips the Git reader for container Instances', function (): void {
    app(InstanceContainer::class)->reserve($this->owner, $this->node);
    app(InstanceDestroyWorktrees::class)->assertAvailable($this->owner);
    expect($this->reader->calls)->toBe(0);
});

it('fails closed with a distinct cause when the Node cannot be read', function (RemoteProgramFailure $failure): void {
    $this->reader->failure = $failure;
    try {
        app(InstanceDestroyWorktrees::class)->assertAvailable($this->owner);
        test()->fail('Unreadable Node was allowed');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.host_unavailable')->and($exception->status)->toBe(409)
            ->and($exception->details['reason'])->toBe($failure->value);
    }
    expect(Operation::query()->count())->toBe(0)->and(InstanceRemoval::query()->count())->toBe(0);
})->with([RemoteProgramFailure::Unreachable, RemoteProgramFailure::TimedOut, RemoteProgramFailure::NoResult]);

it('refuses a real Git error without claiming that dependents were found', function (): void {
    rename($this->root.'/owner/.git', $this->root.'/saved-git');
    file_put_contents($this->root.'/owner/.git', 'gitdir: /does-not-exist');
    try {
        app(InstanceDestroyWorktrees::class)->assertAvailable($this->owner);
        test()->fail('Invalid Git was allowed');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.remove_refused')->and($exception->details['reason'])->toBe('git_error');
    }
    expect(Operation::query()->count())->toBe(0)->and(is_dir($this->root.'/owner'))->toBeTrue();
});

it('refuses moved Git dependencies from live Node facts before any admission write', function (string $shape): void {
    if ($shape === 'moved') {
        rename($this->root.'/dependent', $this->root.'/moved');
        $this->dependent->update(['checkout_path' => $this->root.'/moved']);
    }
    $this->node->accessibleNodes()->syncWithoutDetaching([$this->node->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])
        ->deleteJson('/api/v1/instances/'.$this->owner->id, ['force' => true])
        ->assertConflict()->assertJsonPath('error.code', 'instance.worktree_dependents')
        ->assertJsonPath('error.details.dependents.0.id', $this->dependent->id);
    expect(Operation::query()->count())->toBe(0)->and(InstanceRemoval::query()->count())->toBe(0);
    new LocalProcess(['git', '-C', $this->dependent->checkout_path, 'status', '--porcelain'])->mustRun();
})->with(['moved']);

it('retains the Node checkout inventory for admission only', function (): void {
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    $inventory = app(InstanceDestroyWorktrees::class)->assertAvailable($this->owner)['checkout_instances'];
    expect($inventory)->toContain(['id' => $this->owner->id, 'path' => $this->owner->checkout_path],
        ['id' => $this->dependent->id, 'path' => $this->dependent->checkout_path]);
});

it('skips Node reads without a materialized host checkout on an active Node', function (string $case): void {
    if ($case === 'no_path') {
        $this->owner->setAttribute('checkout_path', null);
    } elseif ($case === 'empty_path') {
        $this->owner->checkout_path = '';
    } elseif ($case === 'reserved_without_runtime_or_source') {
        $this->owner->status = InstanceState::Reserved;
        $this->owner->checkout_path = $this->root.'/not-created';
    } else {
        $this->node->update(['status' => LifecycleStatus::Failed]);
        $this->owner->unsetRelation('node');
    }
    expect(app(InstanceDestroyWorktrees::class)->assertAvailable($this->owner))->toBe(['checkout_instances' => [], 'checkout_warnings' => []])
        ->and($this->reader->calls)->toBe(0);
})->with(['no_path', 'empty_path', 'reserved_without_runtime_or_source', 'inactive_node']);

it('classifies a broken recorded pointer without blocking unrelated admission', function (bool $intoTarget): void {
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    new LocalProcess(['git', 'clone', '-q', $this->root.'/owner', $this->root.'/dependent'])->mustRun();
    new Filesystem()->deleteDirectory($this->root.'/dependent/.git');
    file_put_contents($this->root.'/dependent/.git', 'gitdir: '.($intoTarget ? $this->root.'/owner/.git/worktrees/missing' : $this->root.'/missing-elsewhere'));
    if ($intoTarget) {
        try {
            app(InstanceDestroyWorktrees::class)->assertAvailable($this->owner);
            test()->fail('A pointer into the target was allowed');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('instance.worktree_dependents')->and($exception->details['dependents'][0]['id'])->toBe($this->dependent->id)
                ->and($exception->details['dependent_paths'])->toBe([$this->dependent->checkout_path]);
        }
    } else {
        expect(app(InstanceDestroyWorktrees::class)->assertAvailable($this->owner)['checkout_warnings'])->toBe([['code' => 'instance.checkout_unknown', 'path' => $this->dependent->checkout_path, 'cause' => 'unreadable', 'instance_ids' => [$this->dependent->id]]]);
    }
    expect(is_dir($this->root.'/owner/.git'))->toBeTrue();
})->with([true, false]);

it('retains named unknown warnings from admission through workflow input and the successful Operation result', function (): void {
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    $this->owner->update(['branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $operation = app(StartInstanceDestroyAction::class)->execute($this->owner, true);
    $warning = ['code' => 'instance.checkout_unknown', 'path' => $this->dependent->checkout_path, 'cause' => 'unreadable', 'instance_ids' => [$this->dependent->id]];
    expect($operation->input['checkout_warnings'])->toBe([$warning]);
    $projection = app(DevelopmentInstanceDestroyProjection::class);
    $input = $projection->input($operation);
    expect($input['intent'])->not->toHaveKey('checkout_instances');
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-destroy'], 'finished_at' => null])->save();
    $terminal = ['status' => 'succeeded', 'run_id' => 'fixture-destroy', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => []];
    $projection->apply($operation, $terminal);
    expect($operation->fresh()->result)->toBe(['removed' => true, 'warnings' => [$warning]]);
    $projection->apply($operation->fresh(), $terminal);
    expect($operation->fresh()->result)->toBe(['removed' => true, 'warnings' => [$warning]]);
});

it('excludes container empty and relative paths and never rechecks an admitted removal on retry or dispatch', function (): void {
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    app(InstanceContainer::class)->reserve($this->dependent, $this->node);
    $this->dependent->update(['checkout_path' => InstanceContainer::workspaceOnNode($this->dependent)]);
    $policy = app(InstanceDestroyWorktrees::class);
    foreach (['empty' => '', 'relative' => 'relative/path'] as $name => $path) {
        Instance::query()->create(['project_id' => $this->owner->project_id, 'node_id' => $this->node->id, 'name' => $name,
            'checkout_path' => $path, 'status' => 'reserved', 'source_layout' => 'checkout', 'root' => '.']);
    }
    expect($policy->inventory($this->owner))->toBe([['id' => $this->owner->id, 'path' => $this->owner->checkout_path]]);
    $this->owner->update(['branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $operation = app(StartInstanceDestroyAction::class)->execute($this->owner, true);
    $calls = $this->reader->calls;
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'add', '-q', '--detach', $this->root.'/late'])->mustRun();
    $this->reader->failure = RemoteProgramFailure::Unreachable;
    $projection = app(DevelopmentInstanceDestroyProjection::class);
    expect($projection->input($operation)['intent'])->not->toHaveKey('checkout_instances');
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-destroy'], 'finished_at' => null])->save();
    $projection->apply($operation, ['status' => 'failed', 'run_id' => 'fixture-destroy', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => 'operation.native_failed', 'result' => []]);
    expect($this->owner->fresh()->status->value)->toBe('removing');
    $retry = app(StartInstanceDestroyAction::class)->execute($this->owner->fresh(), true);
    expect($retry->id)->not->toBe($operation->id)->and($this->reader->calls)->toBe($calls);
    $retry->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-retry'], 'finished_at' => null])->save();
    $projection->apply($retry, ['status' => 'succeeded', 'run_id' => 'fixture-retry', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => []]);
    expect(Instance::query()->find($this->owner->id))->toBeNull()->and($this->reader->calls)->toBe($calls);
});

it('admits a registered web checkout or worktree without a Route and projects a skipped withdrawal', function (string $layout): void {
    $instance = $layout === 'worktree' ? $this->dependent : $this->owner;
    if ($layout === 'checkout') {
        new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    }
    $instance->project->update(['type' => 'laravel-app', 'php_version' => '8.5']);
    $instance->update(['name' => basename($instance->checkout_path), 'source_layout' => $layout, 'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $operation = app(StartInstanceDestroyAction::class)->execute($instance->fresh(), true);
    $projection = app(DevelopmentInstanceDestroyProjection::class);
    $intent = $projection->input($operation)['intent'];
    expect($intent['members'][0]['route_id'])->toBeNull()
        ->and($intent['route_mutations']['restore']['routes'])->toBe([])
        ->and($intent['source_paths'])->toBe([$instance->checkout_path])
        ->and($instance->fresh()->status)->toBe(InstanceState::Removing);
    $run = ['status' => 'succeeded', 'jobs' => [['name' => 'destroy', 'steps' => [
        ['name' => 'Withdraw the Route', 'status' => 'skipped'],
        ['name' => 'Check it is gone', 'status' => 'succeeded'],
    ]]]];
    $definition = app(DirectOperation::class)->definition('instance.destroy');
    $result = new ReflectionMethod(SwampCommandStep::class, 'terminalOutput')
        ->invoke(app(SwampCommandStep::class), $run, $definition['terminal_result']);
    expect($result)->not->toHaveKey('withdrawal');
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'no-route'], 'finished_at' => null])->save();
    $projection->apply($operation, ['status' => 'succeeded', 'run_id' => 'no-route', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $result]);
    expect($operation->fresh()->status->value)->toBe('succeeded')->and($instance->fresh())->toBeNull();
})->with(['checkout', 'worktree']);

it('admits an active Instance with one unpublished Route and removes its record without withdrawal', function (string $status): void {
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $this->owner->project_id, 'node_id' => $this->node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'unpublished.fixture.test', 'status' => 'pending']);
    $this->owner->update(['branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    if ($status === 'failed') {
        $route->update(['status' => 'failed', 'failed_step' => 'Install cert, publish site', 'error_code' => 'route.publish_failed']);
    }
    $route->targets()->create(['instance_id' => $this->owner->id, 'position' => 0]);
    expect($route->sites_published)->toBeFalse();
    $operation = app(StartInstanceDestroyAction::class)->execute($this->owner, true);
    expect($operation->input['members'][0]['route_id'])->toBe($route->id)
        ->and($operation->input['native_destroy']['route_mutations']['restore']['routes'])->toBe([]);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'unpublished'], 'finished_at' => null])->save();
    app(DevelopmentInstanceDestroyProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'unpublished', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => []]);
    expect($route->fresh())->toBeNull()->and($this->owner->fresh())->toBeNull();
})->with(['pending', 'failed']);

it('keeps a published or subsequently failed Route in the withdrawal inventory', function (string $status): void {
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $this->owner->project_id, 'node_id' => $this->node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'published.fixture.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->owner->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $route->update(['status' => $status, 'failed_step' => $status === 'failed' ? 'Install cert, publish site' : null, 'error_code' => $status === 'failed' ? 'route.publish_failed' : null]);
    expect($route->sites_published)->toBeTrue();
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    $this->owner->update(['branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $operation = app(StartInstanceDestroyAction::class)->execute($this->owner, true);
    expect($operation->input['members'][0]['route_id'])->toBe($route->id)
        ->and(array_column($operation->input['native_destroy']['route_mutations']['restore']['routes'], 'route_id'))->toBe([$route->id]);
})->with(['active', 'pending', 'failed']);

it('keeps Route ownership target placement and multiplicity refusals before writes', function (string $unsafe): void {
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $this->owner->project_id, 'node_id' => $this->node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'unsafe.fixture.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $this->owner->id, 'position' => 0]);
    $this->owner->load('routes.targets');
    $candidate = $this->owner->routes->sole();
    // Persistence also guards these shapes; retain admission's defensive checks on the loaded set.
    match ($unsafe) {
        'multiple' => $this->owner->routes->push($route->replicate()),
        'project' => $candidate->project_id = $this->dependent->project_id,
        'targets' => $candidate->setRelation('targets', new Collection),
        'placement' => $candidate->node_id = null,
        'state' => $candidate->status = RouteStatus::Retiring,
    };
    $call = fn () => new ReflectionMethod(StartInstanceDestroyAction::class, 'route')->invoke(app(StartInstanceDestroyAction::class), $this->owner);
    expect(containerRefusal($call))->toBe('instance.remove_refused')
        ->and($this->owner->fresh()->status)->toBe(InstanceState::Active)
        ->and(Operation::query()->count())->toBe(0)->and(InstanceRemoval::query()->count())->toBe(0);
})->with(['multiple', 'project', 'targets', 'placement', 'state']);
