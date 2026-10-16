<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskTopologyFailedException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmTopologyState;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Jobs\TaskVms\TransferTaskVmRoleImages;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

/**
 * The SSH of the VM's Incus host, which checks and transfers the recipe base image. The VM holds it unless a test queues
 * another answer: exit 3 when it does not.
 *
 * @param  list<CommandResult>  $results
 */
function topology_admission_host(array $results = []): AppDevFakeSshExecutor
{
    $ssh = new AppDevFakeSshExecutor($results);
    app()->instance(SshExecutor::class, $ssh);

    return $ssh;
}

/** @param list<CommandResult> $results */
function topology_admission_ssh(array $results): AppDevFakeSshExecutor
{
    $ssh = new AppDevFakeSshExecutor($results);
    app()->instance(DevelopmentSshExecutor::class, new DevelopmentSshExecutor($ssh, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 test';
        }
    }, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    }));

    return $ssh;
}

function topology_admission_result(string $stdout, int $exit = 0): CommandResult
{
    return new CommandResult($exit, $stdout, '', 1, false);
}

/** @param list<string> $topology */
function topology_admission_subtask(Task $group, array $topology, int $position = 1): Task
{
    return Task::query()->create(['parent_id' => $group->id, 'position' => $position, 'title' => 'Prove', 'brief' => 'On nodes', 'status' => TaskStatus::Todo, 'topology' => $topology]);
}

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/nckrtl/orbit.git', 'default_branch' => 'main']);
    $this->node = Node::query()->create([
        'name' => 'tvm-1', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.130', 'wireguard_ip' => '10.44.0.130', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:vm',
    ]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace']);
    $this->group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Nodes', 'brief' => 'Work', 'status' => TaskGroupStatus::Running, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id,
    ]);
    $host = Node::query()->create(['name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '192.0.2.7', 'user' => 'orbit']);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $build = TaskVmRecipeImages::ready($host->id);
    $this->vm = TaskVm::query()->create([
        'group_id' => $this->group->id, 'node_id' => $this->node->id, 'host_node_id' => $host->id, 'provider' => 'incus', 'size' => TaskVmSize::Medium,
        'recipe_identity' => $build->recipe_identity, 'image_fingerprint' => $build->image_fingerprint, 'name' => 'tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.130',
    ]);
    $this->host = topology_admission_host();
});

it('admits a subtask without a topology without touching the VM', function (): void {
    $ssh = topology_admission_ssh([]);

    app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, []));

    expect($ssh->commands)->toBe([])->and($this->vm->fresh()?->topology_state)->toBeNull();
});

it('rejects pre-recipe groups before starting topology preparation', function (): void {
    $this->vm->update(['recipe_identity' => null]);
    $ssh = topology_admission_ssh([]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, ['app-dev'])))
        ->toThrow(TaskTopologyFailedException::class, 'Drain or cancel it before deploying the recipe-only topology harness');
    expect($ssh->commands)->toBe([]);
});

it('starts the preparation unit once, waits while it runs, and admits the subtask when it is ready', function (): void {
    $ssh = topology_admission_ssh([
        topology_admission_result(''),
        topology_admission_result('{"state":"running"}'),
        topology_admission_result('{"state":"ready","step":"acquire","seconds":690,"log":""}'),
        topology_admission_result('{"state":"ready","step":"acquire","seconds":690,"log":""}'),
    ]);
    $subtask = topology_admission_subtask($this->group, ['app-prod+ingress']);
    $admission = app(TaskTopologyAdmission::class);

    expect(fn () => $admission->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'Task VM: preparing the topology app-prod+ingress.');
    $vm = $this->vm->fresh();
    $start = $ssh->commands[0];
    expect($vm?->topology_state)->toBe(TaskVmTopologyState::Preparing)
        ->and($vm?->topology->toArray())->toBe(['app-prod+ingress'])
        ->and($vm?->topology_run)->toMatch('/\A[0-9a-f]{16}\z/')
        ->and(array_slice($start->arguments, 3))->toBe(['topology', 'start', $vm?->topology_run, '/home/orbit/apps/orbit/workspace', 'TASK-'.$this->group->id, 'app-prod+ingress', '25GiB', '10.44.0.0/24', '/var/lib/orbit/task-vm-role-images/manifest.json'])
        ->and($start->arguments[0])->toBe('bash')
        ->and($start->input)->toBe(file_get_contents(resource_path('task-vms/topology.sh')));

    expect(fn () => $admission->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'preparing the topology');
    $admission->prepare($this->group, $subtask);

    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Ready)
        ->and($ssh->commands)->toHaveCount(4)
        ->and(array_slice($ssh->commands[1]->arguments, 3))->toBe(['topology', 'status', $vm?->topology_run]);
});

it('prepares the union again when a later subtask declares more than the ready topology holds', function (): void {
    $this->vm->update(['size' => TaskVmSize::Large, 'topology' => ['app-dev'], 'topology_state' => TaskVmTopologyState::Ready]);
    topology_admission_subtask($this->group, ['app-dev']);
    $later = topology_admission_subtask($this->group, ['app-prod+ingress'], 2);
    $ssh = topology_admission_ssh([topology_admission_result('')]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $later))
        ->toThrow(TaskCapacityException::class, 'Task VM: preparing the topology app-dev, app-prod+ingress.');
    expect($ssh->commands[0]->arguments[8])->toBe('app-dev,app-prod+ingress')
        ->and($ssh->commands[0]->arguments[9])->toBe('45GiB')
        ->and($this->vm->fresh()?->topology->toArray())->toBe(['app-dev', 'app-prod+ingress']);
});

it('asks for assistance with the step and the redacted log when the unit fails, and forgets the run', function (string $status, string $message): void {
    $this->vm->update(['topology' => ['app-dev'], 'topology_state' => TaskVmTopologyState::Preparing, 'topology_run' => 'abcdef0123456789']);
    topology_admission_ssh([topology_admission_result($status)]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, ['app-dev'])))
        ->toThrow(fn (TaskTopologyFailedException $failure) => expect($failure->getMessage())->toContain($message)->toEndWith('Post a resolution to prepare it again.')
            ->not->toContain('topology-secret-value'));

    expect($this->vm->fresh()?->topology_state)->toBeNull()
        ->and($this->vm->fresh()?->topology_run)->toBeNull()
        ->and($this->vm->fresh()?->topology_error)->toContain($message);
})->with([
    'failed step' => ['{"state":"failed","step":"images","seconds":12,"log":"build failed; token=topology-secret-value"}', 'Preparing the topology failed at step images. The end of its log: build failed'],
    'lost unit' => ['{"state":"lost"}', 'The topology preparation stopped without a result.'],
]);

it('waits when the unit state cannot be read, and fails when it cannot start', function (): void {
    $this->vm->update(['topology' => ['app-dev'], 'topology_state' => TaskVmTopologyState::Preparing, 'topology_run' => 'abcdef0123456789']);
    topology_admission_ssh([topology_admission_result('not json')]);
    $subtask = topology_admission_subtask($this->group, ['app-dev']);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'could not be read');
    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Preparing);

    $this->vm->update(['topology_state' => null]);
    topology_admission_ssh([new CommandResult(1, '', 'sudo: a password is required', 1, false), topology_admission_result('{"state":"lost"}')]);
    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskTopologyFailedException::class, 'failed at step start. The end of its log: sudo: a password is required');
});

it('makes a subtask wait when its group cannot run its topology', function (): void {
    topology_admission_ssh([]);
    $subtask = topology_admission_subtask($this->group, ['app-dev', 'app-prod']);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, TaskVmTopologyAdmission::TooSmall);
});

it('reads the newest MiB of the JSON evidence and counts its records since the handoff', function (): void {
    $line = static fn (int $minutes, string $label, string $node, int $exit): string => json_encode([
        'v' => 1, 'start' => CarbonImmutable::now()->utc()->subMinutes($minutes)->format('Y-m-d\\TH:i:s.v\\Z'), 'end' => CarbonImmutable::now()->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
        'duration_ms' => 10, 'label' => $label, 'node' => $node, 'exit' => $exit, 'argv' => ['orbit', 'node:list'],
    ], JSON_THROW_ON_ERROR)."\n";
    $ssh = topology_admission_ssh([topology_admission_result(
        ".e2e/evidence.jsonl\n".$line(60, 'before handoff', 'app-dev', 0).$line(10, 'ingress check', 'app-prod', 1).$line(5, 'node list', 'app-dev', 0),
    )]);

    $evidence = app(TaskTopologyAdmission::class)->evidence($this->group, CarbonImmutable::now()->subMinutes(30));

    expect($evidence->missing(['app-dev', 'app-prod']))->toBe(['app-prod'])
        ->and(array_slice($ssh->commands[0]->arguments, 3))->toBe(['evidence', '/home/orbit/apps/orbit/workspace', '.e2e/evidence.jsonl'])
        ->and($ssh->commands[0]->arguments[2])->toContain('tail -c 1048576');
});

it('keeps a run whose unit started although the start call failed, and fails one without a unit', function (): void {
    $subtask = topology_admission_subtask($this->group, ['app-dev']);
    topology_admission_ssh([new CommandResult(255, '', 'Connection timed out', 1, false), topology_admission_result('{"state":"running"}')]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'preparing the topology app-dev');
    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Preparing);

    $this->vm->update(['topology_state' => null]);
    topology_admission_ssh([new CommandResult(255, '', 'Connection timed out', 1, false), topology_admission_result('{"state":"lost"}')]);
    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskTopologyFailedException::class, 'failed at step start. The end of its log: Connection timed out');
});

it('prepares the topology again when the VM restarted since it was ready', function (): void {
    $this->vm->update(['topology' => ['app-dev'], 'topology_state' => TaskVmTopologyState::Ready, 'topology_run' => 'abcdef0123456789']);
    $ssh = topology_admission_ssh([topology_admission_result('{"state":"stale"}'), topology_admission_result('')]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, ['app-dev'])))
        ->toThrow(TaskCapacityException::class, 'Task VM: preparing the topology app-dev.');
    expect(array_slice($ssh->commands[0]->arguments, 3))->toBe(['topology', 'status', 'abcdef0123456789'])
        ->and($ssh->commands[1]->arguments[4])->toBe('start')
        ->and($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Preparing)
        ->and($this->vm->fresh()?->topology_run)->not->toBe('abcdef0123456789');
});

it('counts no topology evidence without the JSON record file', function (): void {
    topology_admission_ssh([topology_admission_result('')]);

    $evidence = app(TaskTopologyAdmission::class)->evidence($this->group, CarbonImmutable::now()->subMinutes(30));

    expect($evidence->missing(['app-dev', 'app-prod']))->toBe(['app-dev', 'app-prod']);
});

it('transfers the recipe base image off the tick when the VM lacks it, then starts the unit once the VM holds it', function (): void {
    Queue::fake();
    $absent = new CommandResult(3, '', '', 1, false);
    $host = topology_admission_host([$absent, $absent]);
    $ssh = topology_admission_ssh([topology_admission_result('')]);
    $subtask = topology_admission_subtask($this->group, ['app-dev']);
    $admission = app(TaskTopologyAdmission::class);
    $waits = static fn (TaskCapacityException $wait) => expect($wait->wait)->toBe(TaskWait::RecipeImages)->and($wait->getMessage())->toBe(TaskVmTopologyAdmission::Importing);

    expect(fn () => $admission->prepare($this->group, $subtask))->toThrow($waits);
    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Importing)
        ->and($host->commands[0]->arguments[6])->toBe('check')
        ->and($ssh->commands)->toBe([]);
    Queue::assertPushed(TransferTaskVmRoleImages::class, fn (TransferTaskVmRoleImages $job): bool => $job->taskVmId === $this->vm->id);

    expect(fn () => $admission->prepare($this->group, $subtask))->toThrow($waits);
    expect($ssh->commands)->toBe([]);

    expect(fn () => $admission->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'Task VM: preparing the topology app-dev.');
    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Preparing)
        ->and($ssh->commands)->toHaveCount(1)
        ->and($ssh->commands[0]->arguments[4])->toBe('start');
});

it('starts the unit without a transfer when the VM holds the claimed recipe base image', function (): void {
    Queue::fake();
    $ssh = topology_admission_ssh([topology_admission_result('')]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, ['app-dev'])))
        ->toThrow(TaskCapacityException::class, 'Task VM: preparing the topology app-dev.');
    expect($this->host->commands)->toHaveCount(1)
        ->and($this->host->commands[0]->arguments[6])->toBe('check')
        ->and($ssh->commands[0]->arguments[4])->toBe('start');
    Queue::assertNotPushed(TransferTaskVmRoleImages::class);
});

it('waits when the host cannot tell whether the VM holds the recipe base image', function (): void {
    Queue::fake();
    topology_admission_host([new CommandResult(1, '', 'Error: Instance is not running', 1, false)]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, ['app-dev'])))
        ->toThrow(fn (TaskCapacityException $wait) => expect($wait->wait)->toBe(TaskWait::RecipeImages)->and($wait->getMessage())->toContain('could not be read', 'Instance is not running'));
    expect($this->vm->fresh()?->topology_state)->toBeNull();
    Queue::assertNotPushed(TransferTaskVmRoleImages::class);
});

it('asks for assistance when the transfer failed its last try, and transfers again after a resolution', function (): void {
    Queue::fake();
    $this->vm->update(['topology_state' => TaskVmTopologyState::Importing]);
    $job = new TransferTaskVmRoleImages($this->vm->id);
    $job->failed(new RuntimeException('The host could not transfer the claimed recipe base image to the task VM. Exit code [67].'));
    $absent = new CommandResult(3, '', '', 1, false);
    topology_admission_host([$absent, $absent]);
    $subtask = topology_admission_subtask($this->group, ['app-dev']);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))
        ->toThrow(TaskTopologyFailedException::class, 'Preparing the topology failed at step recipe-images. The end of its log: The host could not transfer');
    expect($this->vm->fresh()?->topology_state)->toBeNull();
    Queue::assertNotPushed(TransferTaskVmRoleImages::class);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, TaskVmTopologyAdmission::Importing);
    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Importing)
        ->and($this->vm->fresh()?->topology_error)->toBeNull();
    Queue::assertPushed(TransferTaskVmRoleImages::class);
});

it('transfers the recipe base image only while the row is importing', function (): void {
    $host = topology_admission_host();

    app()->call([new TransferTaskVmRoleImages($this->vm->id), 'handle']);
    expect($host->commands)->toBe([]);

    $this->vm->update(['topology_state' => TaskVmTopologyState::Importing]);
    app()->call([new TransferTaskVmRoleImages($this->vm->id), 'handle']);
    expect($host->commands)->toHaveCount(1)
        ->and($host->commands[0]->arguments[6])->toBe('transfer');
});
