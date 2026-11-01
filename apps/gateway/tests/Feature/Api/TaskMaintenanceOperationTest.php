<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskMaintenanceContinuation;
use App\Domain\Tasks\TaskMaintenanceIntent;
use App\Domain\Tasks\TaskMaintenanceProjection;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $node = $this->markAsGateway(Node::query()->create(['name' => 'maintenance-fixture', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin']));
    $project = Project::query()->create(['name' => 'Maintenance', 'slug' => 'maintenance', 'repository_url' => 'git@github.com:acme/maintenance.git', 'default_branch' => 'main', 'task_check' => 'true']);
    $this->group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Maintain', 'brief' => 'Maintain', 'status' => TaskGroupStatus::Running, 'orchestration' => 'external',
        'maintenance' => ['mode' => 'routine', 'project' => ['projectId' => $project->id, 'repository' => 'https://github.com/acme/maintenance.git', 'check' => 'true', 'defaultBranch' => 'main', 'worker' => ['composerPaths' => ['.'], 'jsPaths' => [], 'policy' => []]], 'targets' => [], 'stage' => 'tasks.maintenance']]);
    $this->child = Task::query()->create(['parent_id' => $this->group->id, 'position' => 1, 'title' => 'Maintain', 'brief' => 'Maintain', 'status' => TaskStatus::Todo, 'deliverables' => []]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'maintenance-workspace', 'task_workspace_owner_id' => $this->group->id, 'branch_override' => 'task-'.$this->group->id, 'source_layout' => 'checkout', 'checkout_path' => '/srv/maintenance', 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved]);
    $this->group->taskable()->associate($instance);
    $this->group->save();
    TaskVm::query()->create(['group_id' => $this->group->id, 'node_id' => $node->id, 'host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-maintenance', 'wireguard_ip' => $node->wireguard_ip, 'state' => TaskVmState::Ready]);
    config(['swamp.run_token' => 'fixture.fake', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => $message['payload']['workflowIdOrName'], 'runId' => '005e43f4-02fc-4bca-b43a-5e2456cb4258']]];
    app()->instance(WebSocketClient::class, $socket);
    $this->operation = app(TaskMaintenanceIntent::class)->start($this->group);
    $this->group->update(['maintenance' => [...$this->group->maintenance, 'operation_id' => $this->operation->id]]);
    $this->data = ['run_id' => '005e43f4-02fc-4bca-b43a-5e2456cb4258', 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['group_id' => $this->group->id, 'project_id' => $project->id, 'instance_id' => $instance->id, 'subtask' => $this->child->id, 'outcome' => 'passed', 'changed' => true]];
});

it('admits exactly one normal handoff check from the terminal maintenance receipt', function (): void {
    $projection = app(TaskMaintenanceProjection::class);
    $projection->apply($this->operation, $this->data);
    $projection->apply($this->operation->fresh(), $this->data);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and(Operation::query()->where('kind', 'tasks.check')->count())->toBe(1)
        ->and($this->child->fresh()->checks()->count())->toBe(1)
        ->and($this->group->fresh()->maintenance['stage'])->toBe('tasks.check');
});

it('settles an existing root while disabled Tasks refuses any downstream admission', function (): void {
    app(TaskExtensionState::class)->disable();
    app(TaskMaintenanceProjection::class)->apply($this->operation, $this->data);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and(Operation::query()->where('kind', 'tasks.check')->count())->toBe(0)
        ->and($this->group->fresh()->assistance_requested)->toBeTrue();
});

it('retains the workspace and requests investigation after a failed maintenance root', function (): void {
    $data = [...$this->data, 'status' => 'failed', 'error_code' => 'operation.native_failed', 'result' => []];
    app(TaskMaintenanceProjection::class)->apply($this->operation, $data);
    expect(Operation::query()->count())->toBe(1)->and($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($this->group->fresh()->taskable_id)->not->toBeNull()->and($this->group->fresh()->assistance_requested)->toBeTrue();
});

it('refuses a second maintenance root while the original owns the group', function (): void {
    expect(fn () => app(TaskMaintenanceIntent::class)->start($this->group))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('kind', 'tasks.maintenance')->count())->toBe(1);
});

it('never admits a second repair turn after the bounded repair failed its check', function (): void {
    $this->operation->update(['status' => OperationStatus::Succeeded]);
    $check = Operation::query()->create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => OperationStatus::Succeeded, 'input' => [], 'result' => ['outcome' => 'failed'], 'state' => []]);
    $this->group->update(['maintenance' => [...$this->group->maintenance, 'operation_id' => $check->id, 'repair_attempted' => true]]);
    app(TaskMaintenanceContinuation::class)->apply($check);
    app(TaskMaintenanceContinuation::class)->apply($check->fresh());
    expect(Operation::query()->where('kind', 'tasks.turn')->count())->toBe(0)
        ->and($this->group->fresh()->assistance_requested)->toBeTrue()->and($this->group->fresh()->taskable_id)->not->toBeNull();
});

it('requires an exact passed check before native no-change cleanup', function (bool $passed): void {
    $this->operation->update(['status' => OperationStatus::Succeeded]);
    $this->child->update(['status' => TaskStatus::Completed]);
    $check = Operation::query()->create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => OperationStatus::Succeeded, 'input' => [], 'result' => ['outcome' => $passed ? 'passed' : 'failed'], 'state' => []]);
    $this->group->update(['status' => TaskGroupStatus::Settling, 'maintenance' => [...$this->group->maintenance, 'changed' => false, 'check_operation_id' => $check->id]]);
    if ($passed) {
        TaskCancelIntent::eligible($this->group->fresh(), 'tasks.maintenance_finish');
        expect($this->group->fresh()->taskable_id)->not->toBeNull();
    } else {
        expect(fn () => TaskCancelIntent::eligible($this->group->fresh(), 'tasks.maintenance_finish'))->toThrow(ResourceOperationException::class);
    }
})->with([true, false]);

it('requires the exact published PR to end before maintenance cleanup', function (string $state, bool $owned): void {
    $this->operation->update(['status' => OperationStatus::Succeeded]);
    $this->child->update(['status' => TaskStatus::Completed]);
    $check = Operation::query()->create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => OperationStatus::Succeeded, 'input' => [], 'result' => ['outcome' => 'passed'], 'state' => []]);
    $url = 'https://github.com/acme/maintenance/pull/17';
    $publish = Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => OperationStatus::Succeeded, 'input' => [], 'result' => ['pull_request_url' => $owned ? $url : $url.'0'], 'state' => []]);
    $this->group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => $url, 'watched_pr_url' => $url, 'watched_pr_state' => $state,
        'maintenance' => [...$this->group->maintenance, 'changed' => true, 'check_operation_id' => $check->id, 'publish_operation_id' => $publish->id]]);
    if ($state !== 'open' && $owned) {
        TaskCancelIntent::eligible($this->group->fresh(), 'tasks.maintenance_finish');
        expect($this->group->fresh()->taskable_id)->not->toBeNull();
    } else {
        expect(fn () => TaskCancelIntent::eligible($this->group->fresh(), 'tasks.maintenance_finish'))->toThrow(ResourceOperationException::class);
    }
})->with([['open', true], ['closed', true], ['merged', true], ['merged', false]]);
