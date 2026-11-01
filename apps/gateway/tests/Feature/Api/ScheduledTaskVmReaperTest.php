<?php

declare(strict_types=1);

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\ScheduledTaskVmReaper;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->travelTo('2026-11-01 12:00:00');
    enable_operation_queue();
    $this->host = $this->markAsGateway(Node::query()->forceCreate(['id' => 209, 'name' => 'reaper-host', 'status' => 'active', 'user' => 'orbit',
        'platform' => 'linux', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.15',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']));
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)], 'devClusterId' => null]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 PUBLIC_FIXTURE');
    app()->instance(SshKeyProvider::class, $keys);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Reaper work', 'slug' => 'reaper-work', 'repository_url' => 'https://github.com/example/work']);
    $this->group = Task::topLevel()->forceCreate(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'running', 'orchestration' => 'external',
        'created_at' => now()->subHours(3), 'started_at' => now()->subHours(2)]);
    $this->vm = TaskVm::query()->create(['group_id' => $this->group->id, 'provider' => 'incus', 'name' => 'pending', 'state' => 'failed',
        'host_node_id' => $this->host->id, 'wireguard_ip' => '10.44.0.129', 'claimed_at' => now()->subHours(2)]);
    $this->vm->update(['name' => 'tvm-'.$this->vm->id]);
    $node = Node::query()->create(['name' => $this->vm->name, 'status' => 'failed', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $this->vm->wireguard_ip, 'public_ssh_host' => '192.0.2.129', 'user' => 'orbit']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'provisioning']);
    $this->vm->update(['node_id' => $node->id]);
    $this->workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'reaper-workspace',
        'task_workspace_owner_id' => $this->group->id, 'task_workspace_routed' => false, 'source_layout' => 'checkout',
        'branch_override' => 'task-'.$this->group->id, 'checkout_path' => '/home/orbit/apps/reaper/workspace', 'status' => 'source_resolved']);
    $this->group->taskable()->associate($this->workspace);
    $this->group->save();
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/fixture/ca.pem', 'swamp.run_token' => 'fixture.run']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static function (array $sent): array {
        if ($sent['type'] === 'workflow.trigger.set') {
            return [['type' => $sent['type'], 'id' => $sent['id'], 'payload' => ['data' => ['workflowName' => $sent['payload']['workflowName'], 'entry' => ['schedule' => $sent['payload']['schedule'], 'inputs' => $sent['payload']['inputs']]]]]];
        }

        return [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => $sent['payload']['workflowIdOrName'], 'runId' => (string) Str::uuid()]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    $this->schedule = app(ScheduledTaskVmReaper::class);
    $this->state = static fn (): array => json_decode(Setting::query()->where('key', ScheduledTaskVmReaper::StateKey)->firstOrFail()->value, true, 512, JSON_THROW_ON_ERROR);
    $this->selection = function (): array {
        $inputs = ($this->state)()['inputs'];

        return [
            ['id' => (string) Str::uuid(), 'startedAt' => now()->toISOString(), 'status' => 'succeeded', 'inputs' => $inputs],
            ['expectations_hash' => $inputs['expectations_hash'], 'requests' => [[...$inputs['expectations']['vms'][0], 'reason' => 'no_progress']]],
        ];
    };
    $this->schedule->activate();
});

it('publishes public expectations without admitting work and deduplicates metric-only saves', function (): void {
    $this->group->update(['tokens' => 7]);
    $this->vm->touch();
    $this->schedule->publish();

    expect($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowName'])->toBe('task-vm-reaper')
        ->and(($this->state)()['inputs']['expectations']['proof_project_id'])->toBeNull()
        ->and(Operation::query()->count())->toBe(0);
});

it('resolves the proof slug at publication and republishes only a changed expectation set', function (): void {
    Project::query()->forceCreate(['id' => 62, 'slug' => 'swamp-proof', 'name' => 'Swamp Proof', 'repository_url' => 'https://github.com/hardimpactdev/swamp-proof-fixture']);
    $this->schedule->publish();
    $this->schedule->publish();

    expect(($this->state)()['inputs']['expectations']['proof_project_id'])->toBe(62)
        ->and($this->socket->sent)->toHaveCount(2);
});

it('admits normal Tasks cleanup once and keeps group and workspace records until native completion', function (): void {
    [$run, $result] = ($this->selection)();
    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);
    app()->forgetInstance(ScheduledTaskVmReaper::class);
    app(ScheduledTaskVmReaper::class)->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('tasks.cancel')->and($operation->target_id)->toBe($this->group->id)
        ->and($operation->input['task_vm_id'])->toBe($this->vm->id)
        ->and($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($this->workspace->fresh())->not->toBeNull()
        ->and(app(OperationIdempotency::class)->kind)->toBeNull();
});

it('cancels the actual bound hung turn first without admitting cleanup or inventing completion', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://swamp.orbit/api/v1/cancel/workflow-run/*' => Http::response([], 200)]);
    $operation = Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'started_at' => now()->subHours(2), 'input' => [],
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    $this->schedule->publish();
    [$run, $result] = ($this->selection)();

    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);

    Http::assertSentCount(1);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->fresh()->cancel_requested_at)->not->toBeNull()
        ->and(Operation::query()->count())->toBe(1)
        ->and(($this->state)()['terminal']['outcomes'][$this->vm->id])->toBe('operation.target_busy');

    $operation->update(['status' => 'cancelled', 'finished_at' => now(), 'lock_key' => null]);
    $this->travel(1)->minutes();
    [$next, $selected] = ($this->selection)();
    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $next, $selected);

    Http::assertSentCount(1);
    expect(Operation::query()->where('kind', 'tasks.cancel')->count())->toBe(1)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Cancelled);
});

it('rejects changed progress, ownership, failed runs and altered results before cleanup', function (string $cause): void {
    [$run, $result] = ($this->selection)();
    if ($cause === 'progress') {
        $this->group->update(['started_at' => now()]);
    } elseif ($cause === 'ownership') {
        $this->vm->update(['provider_id' => 'different-provider']);
    } elseif ($cause === 'native failure') {
        $run['status'] = 'failed';
    } else {
        $result['expectations_hash'] = str_repeat('b', 64);
    }

    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);

    expect(Operation::query()->count())->toBe(0)->and($this->workspace->fresh())->not->toBeNull();
})->with(['progress', 'ownership', 'native failure', 'wrong hash']);

it('does not consume an unaccepted publication', function (): void {
    [$run, $result] = ($this->selection)();
    $this->socket->reply = static fn (): array => [];
    $this->group->update(['started_at' => now()]);
    expect(fn () => $this->schedule->publish())->toThrow(ResourceOperationException::class);
    expect(($this->state)()['published'])->toBeFalse();

    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);

    expect(Operation::query()->count())->toBe(0);
});

it('lets the next native selection retry failed cleanup but never replays that selection', function (string $terminal): void {
    [$run, $result] = ($this->selection)();
    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);
    $first = Operation::query()->sole();
    $first->update(['status' => $terminal, 'finished_at' => now(), 'lock_key' => null]);
    $this->travel(1)->minutes();
    [$next, $selected] = ($this->selection)();

    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $next, $selected);
    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $next, $selected);

    expect(Operation::query()->count())->toBe(2)
        ->and(Operation::query()->latest('id')->first()->kind)->toBe('tasks.cancel')
        ->and(Operation::query()->latest('id')->first()->idempotency_key)->not->toBe($first->idempotency_key)
        ->and($this->workspace->fresh())->not->toBeNull();
})->with(['failed', 'cancelled']);

it('does not cancel or duplicate an active cleanup on a later native selection', function (): void {
    [$run, $result] = ($this->selection)();
    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);
    $operation = Operation::query()->sole();
    $this->travel(1)->minutes();
    [$next, $selected] = ($this->selection)();

    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $next, $selected);

    expect(Operation::query()->count())->toBe(1)
        ->and($operation->fresh()->cancel_requested_at)->toBeNull();
});

it('uses standalone VM destroy for a claimed orphan without a workspace', function (): void {
    $this->group->taskable()->dissociate();
    $this->group->save();
    $this->workspace->delete();
    $this->vm->update(['group_id' => null]);
    $this->schedule->publish();
    [$run, $result] = ($this->selection)();
    $result['requests'][0]['reason'] = 'no_live_group';

    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('task_vm.destroy')->and($operation->target_type)->toBe('task_vm')
        ->and($operation->target_id)->toBe($this->vm->id);
});

it('uses the shared ended-group cleanup seam and projects only its complete native receipt', function (string $status): void {
    $this->group->update(['status' => $status]);
    $this->schedule->publish();
    [$run, $result] = ($this->selection)();
    $result['requests'][0]['reason'] = 'no_live_group';
    $this->schedule->applyScheduled(ScheduledTaskVmReaper::Workflow, $run, $result);
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('tasks.cancel')
        ->and($this->group->fresh()->status->value)->toBe($status)
        ->and($this->workspace->fresh())->not->toBeNull();

    app(TaskCancelProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded', 'started_at' => now()->toISOString(),
        'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['group_id' => $this->group->id, 'instance_id' => $this->workspace->id, 'cleanup_complete' => true,
            'vm' => ['task_vm_id' => $this->vm->id, 'guest_node_id' => $this->vm->node_id,
                'destroyed' => true, 'cleanup_complete' => true,
                'removal' => ['node_id' => $this->vm->node_id, 'removed' => true, 'offline' => true]]],
    ]);

    expect($this->group->fresh()->status->value)->toBe($status)
        ->and($this->group->fresh()->taskable_id)->toBeNull()
        ->and(Instance::query()->find($this->workspace->id))->toBeNull()
        ->and($this->vm->fresh()->state->value)->toBe('destroyed');
})->with(['completed', 'cancelled']);
