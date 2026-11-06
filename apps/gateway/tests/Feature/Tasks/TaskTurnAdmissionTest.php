<?php

declare(strict_types=1);

use App\Data\Tasks\TaskTurnData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskTurnIntent;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

/** A Swamp socket that accepts every vault put, so the admission writes the Pi token copy without a network. */
function acceptingSwampSocket(): void
{
    app()->instance(WebSocketClient::class, new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        private array $frames = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    });
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
}

it('records the subtask and the prompt of the caller as the one prompt the run sends', function (string $role): void {
    [$group, $subtask] = turnWorld();
    acceptingSwampSocket();

    // The fake socket takes the vault put but never confirms the run start; the admission is recorded before that.
    expect(fn () => app(TaskTurnIntent::class)->start($group, new TaskTurnData($subtask->id, $role, 'Only this turn.', 'new'), 'test', null))
        ->toThrow(ResourceOperationException::class, 'did not confirm its start');
    $operation = Operation::query()->where('kind', 'tasks.turn')->sole();

    expect($operation->input['prompt'])->toBe("Subtask: Build the deny rule\n\nBrief:\nBuild it as the chart shows.\n\nDeliverables:\n- plan (command: `make plan` in .): The plan passes.\n\nPrompt of this turn:\nOnly this turn.");
})->with(['implementer', 'consult']);
