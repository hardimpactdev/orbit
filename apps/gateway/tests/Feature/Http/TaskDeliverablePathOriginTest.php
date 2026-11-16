<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Tasks\NativeDeliverablePathRepository;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
 * Deliverable path validation reads the base tree from the origin, in a disposable bare repository on the Gateway, whether or not the group
 * owns a task copy (docs/reference/tasks.md). Nothing reads the copy's checkout.
 */

const OriginHead = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CopyStart = 'cccccccccccccccccccccccccccccccccccccccc';

beforeEach(function (): void {
    $this->gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(TaskExtensionState::class)->enable();
    // A repository on a host no GitHub App covers is read with no credential.
    $this->project = Project::query()->create(['name' => 'site', 'slug' => 'site', 'repository_url' => 'https://git.example.test/acme/site.git', 'default_branch' => 'main', 'root' => '.']);
    $this->group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $this->runs = [];
    $runs = &$this->runs;
    $this->mock(ProcessRunner::class)->shouldReceive('run')->andReturnUsing(static function (ProcessInvocation $invocation) use (&$runs): CommandResult {
        $runs[] = $invocation->arguments;
        $stdout = match (true) {
            in_array('ls-remote', $invocation->arguments, true) => OriginHead."\trefs/heads/main\n",
            in_array('ls-tree', $invocation->arguments, true) => "100644 blob 1111111111111111111111111111111111111111\tsrc/Present.php\0",
            default => '',
        };

        return new CommandResult(0, $stdout, '', 0, false);
    });
});

/** The group's task copy: a container Instance it owns and is bound to, started at CopyStart. */
function deliverableCopy(Task $group): Instance
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit',
    ]));
    app(AllocateInstanceBridgeAction::class)->execute($node);
    $copy = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/srv/site/task',
        'branch' => 'task-'.$group->id, 'starting_commit' => CopyStart, 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Active]);
    InstanceRuntime::query()->create(['instance_id' => $copy->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$copy->id,
        'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a', 'power_state' => InstanceRuntimePowerState::Running]);
    $group->taskable()->associate($copy)->save();

    return $copy;
}

function fileDeliverable(string $path): array
{
    return ['id' => 'spec', 'type' => 'file', 'description' => 'The file exists.', 'path' => $path, 'change' => 'modified'];
}

it('validates a new subtask\'s deliverable paths against the origin tree, and runs nothing but local Git', function (bool $copy, string $base, string $kind): void {
    if ($copy) {
        deliverableCopy($this->group);
    }

    $missing = $this->postJson('/api/v1/tasks', ['parent_id' => $this->group->id, 'title' => 'Step', 'brief' => 'Brief', 'deliverables' => [fileDeliverable('src/Missing.php')]])
        ->assertUnprocessable();
    expect($missing->json('error.details'))->toBe(['deliverables.0.path' => ["Deliverable spec path src/Missing.php is missing on base {$base} (base_kind={$kind})."]]);
    $this->postJson('/api/v1/tasks', ['parent_id' => $this->group->id, 'title' => 'Step', 'brief' => 'Brief', 'deliverables' => [fileDeliverable('src/Present.php')]])
        ->assertCreated();

    $fetches = array_values(array_filter($this->runs, static fn (array $arguments): bool => in_array('fetch', $arguments, true)));
    expect($fetches)->toHaveCount(2)
        ->and($fetches[0])->toContain('https://git.example.test/acme/site.git', $base)
        ->and(array_unique(array_column($this->runs, 0)))->toBe(['git']);
})->with([
    'a group with a task copy reads the copy\'s starting commit from the origin' => [true, CopyStart, 'resolved'],
    'a group without a copy reads the default branch head from the origin' => [false, OriginHead, 'provisional'],
]);

it('lists the origin tree with no task workspace to read: the reader takes no SSH executor and no workspace', function (): void {
    $repository = new NativeDeliverablePathRepository(app(ProcessRunner::class), app(RepositoryReadAccess::class), new Filesystem);

    expect($repository->files($this->project, CopyStart))->toBe(['src/Present.php'])
        ->and(array_column($this->runs, 0))->toBe(['git', 'git', 'git']);
});
