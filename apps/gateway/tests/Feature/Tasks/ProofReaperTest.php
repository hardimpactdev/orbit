<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\ProofReaper;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    app(TaskExtensionState::class)->enable();
    $this->markAsGateway(Node::query()->forceCreate(['id' => 209, 'name' => 'reaper-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit']));
    config(['swamp.run_token' => 'fixture.fake', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static function (array $sent): array {
        if ($sent['type'] === 'workflow.trigger.set') {
            return [['type' => $sent['type'], 'id' => $sent['id'], 'payload' => ['data' => ['workflowName' => $sent['payload']['workflowName'], 'entry' => ['schedule' => $sent['payload']['schedule'], 'inputs' => json_decode(json_encode($sent['payload']['inputs'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)]]]]];
        }

        return [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => $sent['payload']['workflowIdOrName'], 'runId' => (string) Str::uuid()]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    $this->proof = Project::query()->forceCreate(['id' => ProofReaper::ProjectId, 'name' => 'Swamp Proof', 'slug' => 'swamp-proof', 'repository_url' => 'git@github.com:acme/proof.git']);
    $this->other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'git@github.com:acme/other.git']);
    $group = fn (Project $project, string $title, TaskGroupStatus $status = TaskGroupStatus::Todo): Task => Task::topLevel()->create(['project_id' => $project->id,
        'title' => $title, 'brief' => 'Proof', 'orchestration' => 'external', 'status' => $status]);
    $this->old = $group($this->proof, 'swamp-proof beast:minimal minimal@8');
    $this->running = $group($this->proof, 'swamp-proof shark:minimal minimal@8');
    $this->running->forceFill(['started_at' => now()->subHours(2)])->saveQuietly();
    $this->ended = $group($this->proof, 'swamp-proof lab:minimal minimal@8', TaskGroupStatus::Cancelled);
    $this->foreign = $group($this->other, 'swamp-proof beast:minimal minimal@8');
    $vm = Node::query()->forceCreate(['id' => 305, 'name' => 'tvm-277', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.131', 'public_ssh_host' => '10.251.78.211', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAICfyJ+CMCGNc9YCsyn3OdkhwyeKL0qHT/+ASm3iPNAvT']);
    TaskVm::query()->create(['node_id' => $vm->id, 'group_id' => $this->running->id, 'name' => 'tvm-277', 'wireguard_ip' => $vm->wireguard_ip, 'provider' => 'incus', 'state' => 'ready']);
    $this->reaper = app(ProofReaper::class);
    $this->state = static fn (): array => json_decode(Setting::query()->where('key', ProofReaper::StateKey)->firstOrFail()->value, true, 512, JSON_THROW_ON_ERROR);
    $this->socket->sent = [];
    $this->reaper->activate();
    $this->inputs = $this->socket->sent[0]['payload']['inputs'];
    $this->run = ['id' => (string) Str::uuid(), 'startedAt' => now()->toISOString(), 'completedAt' => now()->toISOString(), 'status' => 'succeeded', 'inputs' => $this->inputs];
});

it('publishes the open Project 62 groups and each running VM pinned target as the schedule inputs', function (): void {
    expect($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowName'])->toBe('proof-reaper')
        ->and($this->socket->sent[0]['payload']['schedule'])->toBe('0 7-59/10 * * * *')
        ->and(array_column($this->inputs['groups'], 'id'))->toBe([$this->old->id, $this->running->id])
        ->and($this->inputs['groups'][1])->toBe(['id' => $this->running->id, 'title' => 'swamp-proof shark:minimal minimal@8',
            'started_at' => $this->running->fresh()->started_at->getTimestamp(), 'running' => true])
        ->and($this->inputs['groups'][0]['running'])->toBeFalse()
        ->and($this->inputs['targets'])->toBe([['group_id' => $this->running->id, 'node_id' => 305, 'name' => 'target', 'host' => '10.44.0.131', 'user' => 'orbit', 'port' => 22,
            'host_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICfyJ+CMCGNc9YCsyn3OdkhwyeKL0qHT/+ASm3iPNAvT'], 'identity_file' => '/etc/orbit/swamp-role-worker/ssh-identity']])
        ->and(Operation::query()->count())->toBe(0);

    $this->reaper->publish();
    expect($this->socket->sent)->toHaveCount(1);
    Task::topLevel()->create(['project_id' => $this->proof->id, 'title' => 'swamp-proof beast:minimal minimal@8', 'brief' => 'Proof', 'orchestration' => 'external', 'status' => TaskGroupStatus::Todo]);
    expect($this->socket->sent)->toHaveCount(2)
        ->and($this->socket->sent[1]['payload']['inputs']['revision'])->toBe($this->inputs['revision'] + 1);
});

it('declares the scheduled result and its input publication grant', function (): void {
    $definition = app(DirectOperation::class)->scheduledResultDefinitions()[ProofReaper::Workflow];
    expect($definition['workflow'])->toBe('proof-reaper')->and($definition['terminal_result']['native'])->toBeTrue()
        ->and(app(DirectOperation::class)->grantDefinitions()['schedule:proof-reaper']['schedule_write'])->toBeTrue();
});

it('cancels each chosen open Project 62 group once with the normal cancel intent', function (): void {
    $result = ['revision' => $this->inputs['revision'], 'cancel' => [['group_id' => $this->old->id, 'reason' => 'superseded'], ['group_id' => $this->foreign->id, 'reason' => 'forged'],
        ['group_id' => $this->ended->id, 'reason' => 'ended']]];
    $this->reaper->applyScheduled(ProofReaper::Workflow, $this->run, $result);
    app()->forgetInstance(ProofReaper::class);
    app(ProofReaper::class)->applyScheduled(ProofReaper::Workflow, $this->run, $result);

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('tasks.cancel')->and($operation->target_id)->toBe($this->old->id)
        ->and($operation->triggered_by)->toBe('swamp:proof-reaper')
        ->and($operation->idempotency_key)->toBe('proof-reaper:'.$this->run['id'].':g'.$this->old->id)
        ->and(($this->state)()['terminal']['run_id'])->toBe($this->run['id']);
});

it('cancels nothing for a failed run, a mismatched revision or an unknown workflow', function (string $cause): void {
    $result = ['revision' => $this->inputs['revision'], 'cancel' => [['group_id' => $this->old->id, 'reason' => 'superseded']]];
    if ($cause === 'failed run') {
        $this->run['status'] = 'failed';
    } else {
        $result['revision']++;
    }
    try {
        $this->reaper->applyScheduled(ProofReaper::Workflow, $this->run, $result);
    } catch (Throwable) {
    }
    expect(Operation::query()->count())->toBe(0);
    expect(fn () => $this->reaper->applyScheduled((string) Str::uuid(), $this->run, $result))->toThrow(LogicException::class);
})->with(['failed run', 'mismatched revision']);
