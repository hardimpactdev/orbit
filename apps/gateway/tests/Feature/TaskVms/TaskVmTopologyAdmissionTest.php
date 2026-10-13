<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskTopologyFailedException;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmTopologyState;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Carbon\CarbonImmutable;
use Tests\Support\AppDevFakeSshExecutor;

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
        'project_id' => $project->id, 'title' => 'Nodes', 'brief' => 'Work', 'status' => TaskGroupStatus::Running,
        'task_compute' => TaskCompute::Vm, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id,
    ]);
    $this->vm = TaskVm::query()->create([
        'group_id' => $this->group->id, 'node_id' => $this->node->id, 'provider' => 'incus', 'size' => TaskVmSize::Medium,
        'name' => 'tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.130',
    ]);
});

it('admits a subtask without a topology without touching the VM', function (): void {
    $ssh = topology_admission_ssh([]);

    app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, []));

    expect($ssh->commands)->toBe([])->and($this->vm->fresh()?->topology_state)->toBeNull();
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
        ->and(array_slice($start->arguments, 3))->toBe(['topology', 'start', $vm?->topology_run, '/home/orbit/apps/orbit/workspace', 'TASK-'.$this->group->id, 'app-prod+ingress', '25GiB', '10.44.0.0/24'])
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

    $shared = Task::topLevel()->create([
        'project_id' => $this->group->project_id, 'title' => 'Shared', 'brief' => 'Work', 'status' => TaskGroupStatus::Running,
        'task_compute' => TaskCompute::Shared, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
    expect(fn () => app(TaskTopologyAdmission::class)->prepare($shared, topology_admission_subtask($shared, ['app-dev'])))->toThrow(TaskCapacityException::class, TaskVmTopologyAdmission::SharedGroup);
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
        ->and(array_slice($ssh->commands[0]->arguments, 3))->toBe(['evidence', '/home/orbit/apps/orbit/workspace', '.e2e/evidence.jsonl', '.e2e/evidence.log'])
        ->and($ssh->commands[0]->arguments[2])->toContain('tail -c 1048576');
});

it('reads the text evidence log of a harness without JSON evidence, and counts nothing without either', function (): void {
    $entry = static fn (string $start, string $label, string $node, int $exit): string => "=== {$start} {$label} node={$node} exit={$exit} duration=10ms end={$start}\n\$ orbit node:list\n--- stdout\nok\n--- stderr\n\n";
    $at = static fn (int $minutes): string => CarbonImmutable::now()->utc()->subMinutes($minutes)->format('Y-m-d\\TH:i:s.v\\Z');
    topology_admission_ssh([
        topology_admission_result(".e2e/evidence.log\n".$entry($at(60), 'before handoff', 'app-dev', 0).$entry($at(10), 'ingress check', 'app-prod', 1).$entry($at(5), 'node list', 'app-dev', 0)),
        topology_admission_result(''),
    ]);
    $admission = app(TaskTopologyAdmission::class);

    expect($admission->evidence($this->group, CarbonImmutable::now()->subMinutes(30))->missing(['app-dev', 'app-prod']))->toBe(['app-prod'])
        ->and($admission->evidence($this->group, CarbonImmutable::now()->subMinutes(30))->missing(['app-dev']))->toBe(['app-dev']);
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
