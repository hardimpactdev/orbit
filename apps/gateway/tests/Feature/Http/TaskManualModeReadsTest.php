<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

/** docs/reference/tasks.md#task-places and the manual-mode reads of 9 November 2026. */
function manual_node(string $name, string $ip): Node
{
    return Node::withoutEvents(function () use ($name, $ip): Node {
        $node = Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit']);
        $node->forceFill(['ssh_host_fingerprint' => 'SHA256:fingerprint'])->save();

        return $node;
    });
}

function manual_group(Project $project, string $title, TaskGroupStatus $status, array $attributes = []): Task
{
    return Task::query()->create(['project_id' => $project->id, 'title' => $title, 'brief' => 'Brief', 'status' => $status, ...$attributes]);
}

function manual_vm(Node $host, string $name, string $pool, ?Task $group, int $slot): TaskVm
{
    return TaskVm::query()->create(['host_node_id' => $host->id, 'group_id' => $group?->id, 'provider' => TaskVmProviderKind::Incus, 'name' => $name,
        'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.'.(140 + $slot), 'size' => TaskVmSize::Large, 'capacity_pool' => $pool, 'slot_number' => $slot]);
}

function manual_operation(Task $group, OperationStatus $status, array $attributes = []): Operation
{
    return Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => null,
        'input' => [], 'status' => $status, ...$attributes]);
}

function manual_read(Node $caller, string $uri): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson($uri);
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->gateway = manual_node('gateway', '10.44.0.2');
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->host = manual_node('beast', '10.44.0.10');
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25',
        [new TaskVmHost(nodeId: $this->host->id, project: 'orbit-tasks', network: 'orbittask0', cidr: '10.251.77.0/24', taskPlaces: 2, cpus: 6, memory: '12GiB', disk: '60GiB', ciPlaces: 2)],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    $this->project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    app(TaskExtensionState::class)->enable();

    // Two groups hold both task places: one idle since 10:00, one with a turn running. A CI proof VM holds one CI place.
    $this->idle = manual_group($this->project, 'Idle group', TaskGroupStatus::Running);
    $this->busy = manual_group($this->project, 'Busy group', TaskGroupStatus::Running);
    manual_vm($this->host, 'beast-tvm-1', 'task', $this->idle, 1);
    manual_vm($this->host, 'beast-tvm-2', 'task', $this->busy, 2);
    manual_vm($this->host, 'beast-tvm-3', 'ci', null, 3);
    Carbon::setTestNow('2026-11-09 09:00:00');
    manual_operation($this->idle, OperationStatus::Succeeded, ['started_at' => '2026-11-09 09:00:05', 'finished_at' => '2026-11-09 10:00:00']);
    Carbon::setTestNow('2026-11-09 11:50:00');
    $this->running = manual_operation($this->busy, OperationStatus::Running, ['started_at' => '2026-11-09 11:50:00']);
    // The group of the outside agent waits behind them.
    Carbon::setTestNow('2026-11-09 11:55:00');
    $this->waiting = manual_group($this->project, 'Manual group', TaskGroupStatus::Todo, ['capacity_wait_reason' => 'capacity']);
    $this->wait = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $this->waiting->id, 'node_id' => null,
        'input' => ['capacity_request' => ['commit' => str_repeat('c', 40)]], 'status' => OperationStatus::Waiting, 'current_step' => 'Wait for a task place']);
    Carbon::setTestNow('2026-11-09 12:00:00');
});

$holders = [
    ['vm' => 'beast-tvm-1', 'pool' => 'task', 'title' => 'Idle group', 'status' => 'running', 'last_activity_at' => '2026-11-09T10:00:00+00:00', 'active_operation_id' => null],
    ['vm' => 'beast-tvm-2', 'pool' => 'task', 'title' => 'Busy group', 'status' => 'running', 'last_activity_at' => '2026-11-09T11:50:00+00:00'],
];

describe('task:status capacity', function () use ($holders): void {
    it('shows the places of each host, who holds them with their last activity, and the task queue', function () use ($holders): void {
        $capacity = manual_read($this->gateway, '/api/v1/tasks/status')->assertOk()->json('data.capacity');

        expect($capacity['hosts'])->toHaveCount(1)
            ->and($capacity['hosts'][0])->toMatchArray(['node_id' => $this->host->id, 'node' => 'beast', 'task_places' => 2, 'task_used' => 2, 'task_free' => 0,
                'ci_places' => 2, 'ci_used' => 1, 'ci_free' => 1])
            ->and($capacity['hosts'][0]['holders'][0])->toMatchArray([...$holders[0], 'group_id' => $this->idle->id])
            ->and($capacity['hosts'][0]['holders'][1])->toMatchArray([...$holders[1], 'group_id' => $this->busy->id, 'active_operation_id' => $this->running->id])
            ->and($capacity['hosts'][0]['holders'][2])->toBe(['vm' => 'beast-tvm-3', 'pool' => 'ci', 'group_id' => null, 'title' => null, 'status' => null,
                'last_activity_at' => null, 'active_operation_id' => null])
            ->and($capacity['queue'])->toBe([['position' => 1, 'operation_id' => $this->wait->id, 'kind' => 'tasks.provision', 'group_id' => $this->waiting->id,
                'title' => 'Manual group', 'waiting_since' => '2026-11-09T11:55:00+00:00']]);
    });

    it('lists only open groups with a review-and-merge pull request under merges', function (): void {
        $this->project->forceFill(['review_and_merge' => true])->save();
        $open = manual_group($this->project, 'Open pull request', TaskGroupStatus::Reviewing, ['pr_url' => 'https://github.com/hardimpactdev/orbit/pull/9']);
        manual_group($this->project, 'Ended pull request', TaskGroupStatus::Completed, ['pr_url' => 'https://github.com/hardimpactdev/orbit/pull/8']);
        manual_group($this->project, 'Filed problem', TaskGroupStatus::Backlog);

        $merges = manual_read($this->gateway, '/api/v1/tasks/status')->assertOk()->json('data.merges');

        expect(array_column($merges, 'id'))->toBe([$open->id]);
    });

    it('lists an incoming pull request under merges only once it has its pull request', function (): void {
        $incoming = manual_group($this->project, 'Review #460', TaskGroupStatus::Reviewing, ['pr_url' => 'https://github.com/hardimpactdev/orbit/pull/460', 'pr_branch' => 'cursor/login']);
        manual_group($this->project, 'Review #461', TaskGroupStatus::Todo, ['pr_branch' => 'cursor/signup']);

        expect(array_column(manual_read($this->gateway, '/api/v1/tasks/status')->assertOk()->json('data.merges'), 'id'))->toBe([$incoming->id]);
    });

    it('names the newest open Operation of a holder', function (): void {
        $newer = manual_operation($this->busy, OperationStatus::Queued, ['kind' => 'tasks.check']);

        $holders = manual_read($this->gateway, '/api/v1/tasks/status')->assertOk()->json('data.capacity.hosts.0.holders');

        expect($holders[1]['active_operation_id'])->toBe($newer->id);
    });
});

describe('task:show', function () use ($holders): void {
    it('gives a waiting group its queue position, the holders of the task places, and its last activity', function () use ($holders): void {
        $data = manual_read($this->gateway, "/api/v1/tasks/{$this->waiting->id}")->assertOk()->json('data');

        expect($data['capacity_wait']['operation_id'])->toBe($this->wait->id)
            ->and($data['capacity_wait']['position'])->toBe(1)
            ->and($data['capacity_wait']['holders'])->toHaveCount(2)
            ->and($data['capacity_wait']['holders'][1])->toMatchArray($holders[1])
            ->and($data['last_activity_at'])->toBe('2026-11-09T11:55:00+00:00')
            ->and($data['preview_url'])->toBeNull();
    });

    it('gives each group in task:list its last activity', function (): void {
        $groups = collect(manual_read($this->gateway, '/api/v1/tasks')->assertOk()->json('data'))->keyBy('id');

        expect($groups[$this->idle->id]['last_activity_at'])->toBe('2026-11-09T10:00:00+00:00')
            ->and($groups[$this->busy->id]['last_activity_at'])->toBe('2026-11-09T11:50:00+00:00');
    });

    it('gives a group that waits for nothing no capacity_wait', function (): void {
        expect(manual_read($this->gateway, "/api/v1/tasks/{$this->idle->id}")->assertOk()->json('data.capacity_wait'))->toBeNull();
    });

    it('returns the preview URL once the workspace has an active Route', function (string $status, ?string $url): void {
        $workspace = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->host->id, 'name' => 'task-'.$this->idle->id,
            'source_layout' => InstanceSourceLayout::Checkout, 'checkout_path' => '/home/orbit/apps/orbit/task', 'task_workspace_owner_id' => $this->idle->id,
            'status' => InstanceState::Active, 'source_prepare_id' => 'prepare']);
        $this->idle->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id, 'preview' => true]);
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
        $route = Route::withoutEvents(fn (): Route => Route::query()->create(['kind' => 'app', 'project_id' => $this->project->id, 'cluster_id' => $cluster->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => 'task-'.$this->idle->id.'.orbit.test', 'status' => 'pending']));
        $route->targets()->create(['instance_id' => $workspace->id, 'position' => 1]);
        // A Route is stored pending and becomes active once it serves.
        $route->forceFill(['status' => $status])->saveQuietly();

        $expected = $url === null ? null : str_replace('{id}', (string) $this->idle->id, $url);
        expect(manual_read($this->gateway, "/api/v1/tasks/{$this->idle->id}")->assertOk()->json('data.preview_url'))->toBe($expected);
    })->with([
        'active' => ['active', 'https://task-{id}.orbit.test'],
        'pending' => ['pending', null],
    ]);
});

describe('operation:show', function (): void {
    it('keeps the waiting request as input, polls at the retry interval, and gives the queue position', function (): void {
        $first = manual_read($this->gateway, "/api/v1/operations/{$this->wait->id}")->assertOk()->json('data');
        $second = manual_read($this->gateway, "/api/v1/operations/{$this->wait->id}")->assertOk()->json('data');

        expect($first['input'])->toBe(['capacity_request' => ['commit' => str_repeat('c', 40)]])
            ->and($second['input'])->toBe($first['input'])
            ->and($first['poll_after_seconds'])->toBe(20)
            ->and($first['capacity_wait']['position'])->toBe(1)
            ->and($first['capacity_wait']['holders'])->toHaveCount(2);
    });

    it('polls a wait that is not for a place by its run time', function (): void {
        $hold = manual_operation($this->idle, OperationStatus::Waiting, ['kind' => 'route.create', 'started_at' => '2026-11-09 11:59:00']);

        expect(manual_read($this->gateway, "/api/v1/operations/{$hold->id}")->assertOk()->json('data'))
            ->toMatchArray(['poll_after_seconds' => 7, 'capacity_wait' => null]);
    });

    it('computes poll_after_seconds from the status and the run time', function (OperationStatus $status, ?string $started, ?int $seconds): void {
        $operation = manual_operation($this->idle, $status, ['started_at' => $started]);

        expect(manual_read($this->gateway, "/api/v1/operations/{$operation->id}")->assertOk()->json('data.poll_after_seconds'))->toBe($seconds)
            ->and(manual_read($this->gateway, "/api/v1/operations/{$operation->id}")->json('data.capacity_wait'))->toBeNull();
    })->with([
        'queued' => [OperationStatus::Queued, null, 2],
        'first ten seconds' => [OperationStatus::Running, '2026-11-09 11:59:52', 2],
        'one minute in' => [OperationStatus::Running, '2026-11-09 11:59:00', 7],
        'three minutes in' => [OperationStatus::Running, '2026-11-09 11:57:00', 15],
        'terminal' => [OperationStatus::Succeeded, '2026-11-09 11:00:00', null],
    ]);
});
