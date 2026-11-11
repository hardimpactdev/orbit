<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\StartTaskScriptData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCheckIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScriptIntent;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** @return array{Task,Task} A running group and its current subtask with an owned, awake workspace. */
function nativeBudgetTaskWorld(): array
{
    $host = SwampNodeFixture::ensure();
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.137', 'wireguard_ip' => '10.44.0.137', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id,
        'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598', 'state' => TaskVmState::Ready,
        'address' => '10.251.78.33', 'wireguard_ip' => $guest->wireguard_ip, 'size' => TaskVmSize::Normal]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace',
        'source_layout' => InstanceSourceLayout::Checkout, 'source_prepare_id' => 'prepare',
        'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id]);
    $subtask = $group->tasks()->create(['title' => 'Work', 'brief' => 'Brief', 'status' => TaskStatus::Todo, 'position' => 1]);
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' fixture']);

    return [$group, $subtask];
}

it('lets five-minute task work follow a quick success without inheriting its short native timeout', function (string $kind): void {
    $this->travelTo(new DateTimeImmutable('2026-11-11T10:00:00Z'));
    Queue::fake([StartAdmittedSwampRun::class, ReconcileSwampOperations::class]);
    [$group, $subtask] = nativeBudgetTaskWorld();
    Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id,
        'node_id' => $group->taskable->node_id, 'input' => [], 'status' => OperationStatus::Succeeded, 'attempts' => 1,
        'started_at' => now()->subHour()->subSeconds(56), 'finished_at' => now()->subHour(), 'state' => ['swamp_run_id' => 'earlier-run']]);

    $operation = $kind === 'tasks.script'
        ? app(TaskScriptIntent::class)->start($group, new StartTaskScriptData($subtask->id, 'scripts/check', []), 'test', null)
        : app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Baseline), 'test', null);

    expect($operation->input['run_budget_seconds'])->toBe(900)
        ->and($operation->input['soft_limit_seconds'])->toBe(120);
    $run = 'ea55ab37-bcbe-4459-81dc-cbb397e7d870';
    $operation->update(['status' => OperationStatus::Running, 'attempts' => 1, 'started_at' => now(), 'state' => ['swamp_run_id' => $run]]);
    app(NativeRunProgress::class)->apply($operation->id, $run,
        ['kind' => 'step_started', 'stepId' => 'work', 'jobId' => 'main', 'seq' => 1, 'startedAt' => now()->toISOString()]);
    $this->travel(5)->minutes();
    $current = app(ExpireOperationAction::class)->execute($operation->refresh());

    expect(OperationRunBudgets::seconds($current))->toBe(120)
        ->and($current->status)->toBe(OperationStatus::Running)
        ->and($current->state['overdue_since'])->toBe('2026-11-11T10:02:00.000000Z')
        ->and($current->cancel_requested_at)->toBeNull()
        ->and($current->input['run_budget_seconds'])->toBeGreaterThan(300);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    Queue::assertNotPushed(ReconcileSwampOperations::class);
})->with(['script' => 'tasks.script', 'check' => 'tasks.check']);
