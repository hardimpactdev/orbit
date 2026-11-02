<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\TaskVms\ImageReaper;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture.fake', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $sent): array => [['type' => $sent['type'], 'id' => $sent['id'], 'payload' => ['data' => [
        'workflowName' => $sent['payload']['workflowName'],
        'entry' => ['schedule' => $sent['payload']['schedule'], 'inputs' => json_decode(json_encode($sent['payload']['inputs'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)],
    ]]]];
    app()->instance(WebSocketClient::class, $this->socket);
    $node = static fn (int $id, LifecycleStatus $status = LifecycleStatus::Active, ?string $key = 'AAAAC3NzaC1lZDI1NTE5AAAAICfyJ+CMCGNc9YCsyn3OdkhwyeKL0qHT/+ASm3iPNAvT'): Node => Node::query()->forceCreate([
        'id' => $id, 'name' => 'host-'.$id, 'status' => $status, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.'.$id,
        'public_ssh_host' => '192.0.2.'.$id, 'user' => 'orbit', 'ssh_host_key_type' => $key === null ? null : 'ssh-ed25519', 'ssh_host_key' => $key]);
    $node(9);
    $node(13);
    $node(14, LifecycleStatus::Removing);
    $node(15, key: null);
    $host = static fn (int $id, array $owned = []): TaskVmHost => new TaskVmHost($id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '20GiB', operatorOwned: $owned);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(false, null, '10.44.200.0/24',
        [$host(9, ['orbit-tasks/keep']), $host(13), $host(14), $host(15)], null, null, null, []));
    $this->build = static fn (int $build, int $host, string $status): Operation => Operation::query()->create(['kind' => 'task_vm.image_build',
        'target_type' => 'task_vm_image', 'target_id' => $build, 'node_id' => $host, 'status' => $status,
        'input' => ['build_id' => $build, 'host_node_id' => $host, 'project' => 'orbit-tasks', 'name' => 'tvm-image-'.$build]]);
    ($this->build)(7, 9, 'running');
    ($this->build)(8, 9, 'waiting');
    ($this->build)(6, 9, 'succeeded');
    ($this->build)(21, 13, 'queued');
    // Enabled after the fixture builds, so nothing was published yet.
    app(TaskExtensionState::class)->enable();
    $this->reaper = app(ImageReaper::class);
});

it('publishes each reachable active host with its settings, active builds and pinned SSH target', function (): void {
    $this->reaper->activate();

    $target = static fn (int $id): array => ['name' => 'target', 'host' => '10.44.0.'.$id, 'user' => 'orbit', 'port' => 22,
        'host_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICfyJ+CMCGNc9YCsyn3OdkhwyeKL0qHT/+ASm3iPNAvT'], 'identity_file' => '/etc/orbit/swamp-role-worker/ssh-identity'];
    expect($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowName'])->toBe('task-vm-image-reaper')
        ->and($this->socket->sent[0]['payload']['schedule'])->toBe('0 */30 * * * *')
        ->and($this->socket->sent[0]['payload']['inputs'])->toBe(['revision' => 1, 'hosts' => [
            ['node_id' => 9, 'project' => 'orbit-tasks', 'pool' => 'orbit-tasks', 'operator_owned' => ['orbit-tasks/keep'], 'protected_builds' => [7, 8], ...$target(9)],
            ['node_id' => 13, 'project' => 'orbit-tasks', 'pool' => 'orbit-tasks', 'operator_owned' => [], 'protected_builds' => [21], ...$target(13)],
        ]]);

    $this->reaper->publish();
    expect($this->socket->sent)->toHaveCount(1);
});

it('republishes when an image build starts or ends', function (): void {
    $this->reaper->activate();
    $started = ($this->build)(30, 13, 'queued');
    $started->update(['status' => 'running']);
    expect($this->socket->sent)->toHaveCount(2)
        ->and($this->socket->sent[1]['payload']['inputs']['hosts'][1]['protected_builds'])->toBe([21, 30]);

    $started->update(['status' => 'failed']);
    expect($this->socket->sent)->toHaveCount(3)
        ->and($this->socket->sent[2]['payload']['inputs']['revision'])->toBe(3)
        ->and($this->socket->sent[2]['payload']['inputs']['hosts'][1]['protected_builds'])->toBe([21]);
});

it('declares the native scheduled result and its input grant, and a terminal only republishes', function (): void {
    $definition = app(DirectOperation::class)->scheduledResultDefinitions()[ImageReaper::Workflow];
    expect($definition['workflow'])->toBe('task-vm-image-reaper')
        ->and($definition['terminal_result'])->toBe(['job' => 'result', 'step' => 'check', 'native' => true])
        ->and(app(DirectOperation::class)->grantDefinitions()['schedule:task-vm-image-reaper']['schedule_write'])->toBeTrue();

    $run = ['id' => (string) Str::uuid(), 'startedAt' => now()->toISOString(), 'status' => 'failed'];
    $this->reaper->applyScheduled(ImageReaper::Workflow, $run, []);
    expect($this->socket->sent)->toHaveCount(1)
        ->and(Operation::query()->where('kind', '!=', 'task_vm.image_build')->count())->toBe(0)
        ->and(fn () => $this->reaper->applyScheduled((string) Str::uuid(), $run, []))->toThrow(LogicException::class);
});

it('publishes nothing while the tasks extension is off', function (): void {
    app(TaskExtensionState::class)->disable();
    $this->reaper->activate();
    ($this->build)(31, 9, 'running');

    expect($this->socket->sent)->toBe([]);
});
