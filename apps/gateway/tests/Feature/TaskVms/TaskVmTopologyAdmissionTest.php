<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskTopologyFailedException;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmTopologyState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Carbon\CarbonImmutable;
use Tests\Support\FakeAgentCommandRunner;

/** @param list<array<string, mixed>|Throwable> $results */
function topology_admission_agents(array $results): FakeAgentCommandRunner
{
    $next = static function () use (&$results): array {
        $result = array_shift($results) ?? throw new LogicException('Unexpected topology call.');
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    };
    $agents = new FakeAgentCommandRunner()
        ->answer('task-vm.start-topology', $next)
        ->answer('task-vm.topology-status', $next);
    app()->instance(AgentCommandRunner::class, $agents);

    return $agents;
}

/** @param list<string> $topology */
function topology_admission_subtask(Task $group, array $topology, int $position = 1): Task
{
    return Task::query()->create(['parent_id' => $group->id, 'position' => $position, 'title' => 'Prove', 'brief' => 'On nodes', 'status' => TaskStatus::Todo, 'topology' => $topology]);
}

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main']);
    $this->node = Node::query()->create([
        'name' => 'tvm-1', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.130', 'wireguard_ip' => '10.44.0.130', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:vm',
    ]);
    $this->group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Nodes', 'brief' => 'Work', 'status' => TaskGroupStatus::Running, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
    test_task_workspace($this->group, ['node_id' => $this->node->id, 'name' => 'task-1', 'branch_override' => null, 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'status' => 'reserved']);
    $this->vm = TaskVm::query()->create([
        'group_id' => $this->group->id, 'node_id' => $this->node->id, 'provider' => 'incus', 'size' => TaskVmSize::Medium,
        'recipe_identity' => 'sha256:'.str_repeat('a', 64), 'name' => 'tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.130',
    ]);
});

it('admits a subtask without a topology without touching the VM', function (): void {
    $agents = topology_admission_agents([]);

    app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, []));

    expect($agents->calls)->toBe([])->and($this->vm->fresh()?->topology_state)->toBeNull();
});

it('starts the preparation unit once, waits while it runs, and admits the subtask when it is ready', function (): void {
    $agents = topology_admission_agents([
        [],
        ['state' => 'running'],
        ['state' => 'ready', 'step' => 'acquire', 'seconds' => 690, 'log' => ''],
        ['state' => 'ready', 'step' => 'acquire', 'seconds' => 690, 'log' => ''],
    ]);
    $subtask = topology_admission_subtask($this->group, ['app-prod+ingress']);
    $admission = app(TaskTopologyAdmission::class);

    expect(fn () => $admission->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'Task VM: preparing the topology app-prod+ingress.');
    $vm = $this->vm->fresh();
    $start = $agents->calls[0];
    expect($vm?->topology_state)->toBe(TaskVmTopologyState::Preparing)
        ->and($vm?->topology->toArray())->toBe(['app-prod+ingress'])
        ->and($vm?->topology_run)->toMatch('/\A[0-9a-f]{16}\z/')
        ->and($start)->toBe(['node' => 'tvm-1', 'command' => 'task-vm.start-topology', 'input' => [
            'run' => $vm?->topology_run, 'checkout' => '/home/orbit/apps/orbit/workspace',
            'issue' => 'TASK-'.$this->group->id, 'nodes' => ['app-prod+ingress'], 'vpn_subnet' => '10.44.0.0/24',
        ]]);

    expect(fn () => $admission->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'preparing the topology');
    $admission->prepare($this->group, $subtask);

    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Ready)
        ->and($agents->calls)->toHaveCount(4)
        ->and($agents->calls[1])->toBe(['node' => 'tvm-1', 'command' => 'task-vm.topology-status', 'input' => ['run' => $vm?->topology_run]]);
});

it('prepares the union again when a later subtask declares more than the ready topology holds', function (): void {
    $this->vm->update(['size' => TaskVmSize::Large, 'topology' => ['app-dev'], 'topology_state' => TaskVmTopologyState::Ready]);
    topology_admission_subtask($this->group, ['app-dev']);
    $later = topology_admission_subtask($this->group, ['app-prod+ingress'], 2);
    $agents = topology_admission_agents([[]]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $later))
        ->toThrow(TaskCapacityException::class, 'Task VM: preparing the topology app-dev, app-prod+ingress.');
    expect($agents->calls[0]['input']['nodes'])->toBe(['app-dev', 'app-prod+ingress'])
        ->and($this->vm->fresh()?->topology->toArray())->toBe(['app-dev', 'app-prod+ingress']);
});

it('asks for assistance with the step and the redacted log when the unit fails, and forgets the run', function (array $status, string $message): void {
    $this->vm->update(['topology' => ['app-dev'], 'topology_state' => TaskVmTopologyState::Preparing, 'topology_run' => 'abcdef0123456789']);
    topology_admission_agents([$status]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, ['app-dev'])))
        ->toThrow(fn (TaskTopologyFailedException $failure) => expect($failure->getMessage())->toContain($message)->toEndWith('Post a resolution to prepare it again.')
            ->not->toContain('topology-secret-value'));

    expect($this->vm->fresh()?->topology_state)->toBeNull()
        ->and($this->vm->fresh()?->topology_run)->toBeNull()
        ->and($this->vm->fresh()?->topology_error)->toContain($message);
})->with([
    'failed step' => [['state' => 'failed', 'step' => 'images', 'seconds' => 12, 'log' => 'build failed; token=topology-secret-value'], 'Preparing the topology failed at step images. The end of its log: build failed'],
    'lost unit' => [['state' => 'lost'], 'The topology preparation stopped without a result.'],
]);

it('waits when the unit state cannot be read, and fails when it cannot start', function (): void {
    $this->vm->update(['topology' => ['app-dev'], 'topology_state' => TaskVmTopologyState::Preparing, 'topology_run' => 'abcdef0123456789']);
    topology_admission_agents([new AgentRunnerFailure('task-vm.topology-status', AgentRunnerFailure::MalformedResult, 'Malformed result')]);
    $subtask = topology_admission_subtask($this->group, ['app-dev']);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'could not be read');
    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Preparing);

    $this->vm->update(['topology_state' => null]);
    topology_admission_agents([new AgentRunnerFailure('task-vm.start-topology', AgentRunnerFailure::Transport, 'sudo: a password is required'), ['state' => 'lost']]);
    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskTopologyFailedException::class, 'failed at step start. The end of its log: sudo: a password is required');
});

it('makes a subtask wait when its group cannot run its topology', function (): void {
    topology_admission_agents([]);
    $subtask = topology_admission_subtask($this->group, ['app-dev', 'app-prod']);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, TaskVmTopologyAdmission::TooSmall);
});

it('counts the agent evidence records since the handoff', function (): void {
    $line = static fn (int $minutes, string $label, string $node, int $exit): string => json_encode([
        'v' => 1, 'start' => CarbonImmutable::now()->utc()->subMinutes($minutes)->format('Y-m-d\\TH:i:s.v\\Z'), 'end' => CarbonImmutable::now()->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
        'duration_ms' => 10, 'label' => $label, 'node' => $node, 'exit' => $exit, 'argv' => ['orbit', 'node:list'],
    ], JSON_THROW_ON_ERROR)."\n";
    $agents = new FakeAgentCommandRunner()->answer('task-vm.read-topology-evidence', ['content_base64' => base64_encode(
        $line(60, 'before handoff', 'app-dev', 0).$line(10, 'ingress check', 'app-prod', 1).$line(5, 'node list', 'app-dev', 0),
    )]);
    app()->instance(AgentCommandRunner::class, $agents);

    $evidence = app(TaskTopologyAdmission::class)->evidence($this->group, CarbonImmutable::now()->subMinutes(30));

    expect($evidence->missing(['app-dev', 'app-prod']))->toBe(['app-prod'])
        ->and($agents->calls)->toBe([[
            'node' => $this->vm->node->name,
            'command' => 'task-vm.read-topology-evidence',
            'input' => ['checkout' => '/home/orbit/apps/orbit/workspace'],
        ]]);
});

it('keeps a run whose unit started although the start call failed, and fails one without a unit', function (): void {
    $subtask = topology_admission_subtask($this->group, ['app-dev']);
    topology_admission_agents([new AgentRunnerFailure('task-vm.start-topology', AgentRunnerFailure::Timeout, 'Connection timed out'), ['state' => 'running']]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskCapacityException::class, 'preparing the topology app-dev');
    expect($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Preparing);

    $this->vm->update(['topology_state' => null]);
    topology_admission_agents([new AgentRunnerFailure('task-vm.start-topology', AgentRunnerFailure::Timeout, 'Connection timed out'), ['state' => 'lost']]);
    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, $subtask))->toThrow(TaskTopologyFailedException::class, 'failed at step start. The end of its log: Connection timed out');
});

it('prepares the topology again when the VM restarted since it was ready', function (): void {
    $this->vm->update(['topology' => ['app-dev'], 'topology_state' => TaskVmTopologyState::Ready, 'topology_run' => 'abcdef0123456789']);
    $agents = topology_admission_agents([['state' => 'stale'], []]);

    expect(fn () => app(TaskTopologyAdmission::class)->prepare($this->group, topology_admission_subtask($this->group, ['app-dev'])))
        ->toThrow(TaskCapacityException::class, 'Task VM: preparing the topology app-dev.');
    expect($agents->calls[0])->toBe(['node' => 'tvm-1', 'command' => 'task-vm.topology-status', 'input' => ['run' => 'abcdef0123456789']])
        ->and($agents->calls[1]['command'])->toBe('task-vm.start-topology')
        ->and($this->vm->fresh()?->topology_state)->toBe(TaskVmTopologyState::Preparing)
        ->and($this->vm->fresh()?->topology_run)->not->toBe('abcdef0123456789');
});

it('counts no topology evidence without the JSON record file', function (): void {
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner()->answer('task-vm.read-topology-evidence', ['content_base64' => null]));

    $evidence = app(TaskTopologyAdmission::class)->evidence($this->group, CarbonImmutable::now()->subMinutes(30));

    expect($evidence->missing(['app-dev', 'app-prod']))->toBe(['app-dev', 'app-prod']);
});

it('refuses unreadable topology evidence instead of accepting a review without it', function (): void {
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner()->refuse('task-vm.read-topology-evidence', 'task_vm.evidence_unreadable'));

    expect(fn () => app(TaskTopologyAdmission::class)->evidence($this->group, CarbonImmutable::now()->subMinutes(30)))
        ->toThrow(TaskCheckException::class, 'Orbit could not read the topology evidence log');
});
