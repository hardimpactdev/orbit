<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskTurnAction;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskVmRemoved;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** A running group with its task VM, workspace and one open subtask that has a brief and a deliverable. */
function turnWorld(): array
{
    SwampNodeFixture::ensure();
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'tvm-598', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.137', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.137', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Group brief', 'status' => TaskGroupStatus::Running,
        'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Build the deny rule', 'brief' => 'Build it as the chart shows.',
        'status' => 'todo', 'deliverables' => [['id' => 'plan', 'type' => 'command', 'description' => 'The plan passes.', 'command' => 'make plan', 'directory' => '.']]]);

    return [$group, $subtask];
}

// A turn of a group without a task copy is refused by the removed runtime's name, even for a group whose VM row is still there.
it('refuses a task VM turn by name before it records a thread or an Operation', function (string $role, ?string $thread): void {
    [$group, $subtask] = turnWorld();
    app(ExtensionStore::class)->set('tasks', true);
    Queue::fake([StartAdmittedSwampRun::class]);

    expect(fn () => app(StartTaskTurnAction::class)->execute($group, new TaskTurnData($subtask->id, $role, 'Only this turn.', $thread), 'test', null))
        ->toThrow(fn (ResourceOperationException $error) => expect([$error->errorCode, $error->status])->toBe([TaskVmRemoved::Code, 422]));
    expect(Operation::query()->count())->toBe(0)
        ->and(AgentThread::query()->count())->toBe(0)
        ->and($subtask->refresh()->implementer_agent_thread_id)->toBeNull();
    Queue::assertNothingPushed();
})->with(['implementer' => ['implementer', 'new'], 'no thread named' => ['implementer', null]]);
