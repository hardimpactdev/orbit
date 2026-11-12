<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceDestroyWorktrees;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Schedule;
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
    $scheduleBefore = $schedule->fresh()->getAttributes();
    $before = Operation::query()->count();
    try {
        app(StartInstanceDestroyAction::class)->execute($this->owner, $force);
        test()->fail('The owner was admitted');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.worktree_dependents')->and($exception->status)->toBe(409)
            ->and($exception->details)->toBe(['dependents' => [['id' => $this->dependent->id, 'name' => 'default', 'project_id' => $this->dependent->project_id]],
                'other_dependents' => 0, 'unowned_paths' => []]);
    }
    expect($schedule->fresh()->getAttributes())->toBe($scheduleBefore);
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
    expect($details)->toBe(['dependents' => [], 'other_dependents' => 1, 'unowned_paths' => ['/unowned']]);
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

it('refuses moved and nested Git dependencies from live Node facts before any admission write', function (string $shape): void {
    if ($shape === 'moved') {
        rename($this->root.'/dependent', $this->root.'/moved');
        $this->dependent->update(['checkout_path' => $this->root.'/moved']);
    } else {
        new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
        $repository = $this->root.'/owner/nested';
        $args = ['git', 'clone', '-q'];
        if ($shape === 'bare') {
            $args[] = '--bare';
        }
        new LocalProcess([...$args, $this->root.'/owner', $repository])->mustRun();
        new LocalProcess(['git', '-C', $repository, 'worktree', 'add', '-q', '--detach', $this->root.'/dependent'])->mustRun();
    }
    $this->node->accessibleNodes()->syncWithoutDetaching([$this->node->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])
        ->deleteJson('/api/v1/instances/'.$this->owner->id, ['force' => true])
        ->assertConflict()->assertJsonPath('error.code', 'instance.worktree_dependents')
        ->assertJsonPath('error.details.dependents.0.id', $this->dependent->id);
    expect(Operation::query()->count())->toBe(0)->and(InstanceRemoval::query()->count())->toBe(0);
    new LocalProcess(['git', '-C', $this->dependent->checkout_path, 'status', '--porcelain'])->mustRun();
})->with(['moved', 'nested', 'bare']);

it('retains the Node checkout inventory for the removal recheck', function (): void {
    new LocalProcess(['git', '-C', $this->root.'/owner', 'worktree', 'remove', '--force', $this->root.'/dependent'])->mustRun();
    $inventory = app(InstanceDestroyWorktrees::class)->assertAvailable($this->owner);
    expect($inventory)->toContain(['id' => $this->owner->id, 'path' => $this->owner->checkout_path],
        ['id' => $this->dependent->id, 'path' => $this->dependent->checkout_path]);
});
