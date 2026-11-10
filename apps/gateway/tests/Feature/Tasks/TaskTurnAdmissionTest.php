<?php

declare(strict_types=1);

use App\Data\Tasks\TaskTurnData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskTurnIntent;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
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
    TaskVm::query()->create(['host_node_id' => $guest->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598',
        'state' => TaskVmState::Ready, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.137', 'size' => TaskVmSize::Normal, 'pi_token' => str_repeat('p', 40)]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Build the deny rule', 'brief' => 'Build it as the chart shows.',
        'status' => 'todo', 'deliverables' => [['id' => 'plan', 'type' => 'command', 'description' => 'The plan passes.', 'command' => 'make plan', 'directory' => '.']]]);

    return [$group, $subtask];
}

it('records the subtask and the prompt of the caller as the one prompt the run sends', function (string $role): void {
    [$group, $subtask] = turnWorld();
    Queue::fake([StartAdmittedSwampRun::class]);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
    $frames = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame) use (&$frames): void {
        $frames[] = $frame;
    });
    // The vault copy is accepted, then the connection ends without confirming the submitted run.
    $socket->shouldReceive('isConnected')->andReturnUsing(function () use (&$frames): bool {
        return ! in_array('workflow.run', array_column($frames, 'type'), true);
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frames): array {
        $frame = end($frames);

        return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
            'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);

    expect(app(TaskTurnIntent::class)->start($group, new TaskTurnData($subtask->id, $role, 'Only this turn.', 'new'), 'test', null)->status)->toBe(OperationStatus::Queued);
    $operation = Operation::query()->where('kind', 'tasks.turn')->sole();

    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->error_code)->toBeNull()
        ->and($operation->input['subtask'])->toBe($subtask->id)
        ->and($operation->input['role'])->toBe($role)
        ->and($frames)->toBe([]);
    expect($operation->input['prompt'])->toBe("Subtask: Build the deny rule\n\nBrief:\nBuild it as the chart shows.\n\nDeliverables:\n- plan (command: `make plan` in .): The plan passes.\n\nPrompt of this turn:\nOnly this turn.");
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $operation->id);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);

    expect(fn () => app()->call([new StartAdmittedSwampRun($operation->id), 'handle']))
        ->toThrow(function (ResourceOperationException $exception) use ($operation): void {
            expect($exception->errorCode)->toBe('swamp.start_uncertain')
                ->and($exception->status)->toBe(503)
                ->and($exception->getMessage())->toBe('Swamp did not confirm its start. A native run may exist; inspect Swamp before another admission.')
                ->and($exception->details)->toBe(['operation_id' => $operation->id]);
        });
    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->error_code)->toBe('swamp.start_uncertain')
        ->and($operation->state)->not->toHaveKey('swamp_run_id');
    expect(array_column($frames, 'type'))->toBe(['vault.put', 'workflow.run'])
        ->and($frames[1]['payload']['workflowIdOrName'])->toBe('operation-task-turn')
        ->and($frames[1]['payload']['inputs']['intent']['prompt'])->toBe($operation->input['prompt']);
})->with(['implementer', 'consult']);
