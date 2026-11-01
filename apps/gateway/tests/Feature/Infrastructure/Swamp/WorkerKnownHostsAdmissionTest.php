<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitWorkerKnownHostsAction;
use App\Domain\Nodes\WorkerKnownHostsProjection;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    Node::unguarded(fn () => Node::query()->create(['id' => 209, 'name' => 'control-worker', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit', 'platform' => 'linux']));
    config(['swamp.run_token' => 'fixture.fake']);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    $this->node = FleetFixtures::node('known-hosts-target');
    app()->bind(WorkerKnownHostsPublisher::class, AdmitWorkerKnownHostsAction::class);
});

it('admits changed pins once and ignores unrelated Node updates', function (): void {
    $this->node->update(['name' => 'unrelated-change']);
    expect(Operation::query()->count())->toBe(0);
    $this->node->update(['ssh_host_key' => 'replacement-public-key']);
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('node.worker_known_hosts');
    expect($operation->node_id)->toBe(209);
    expect($this->socket->sent)->toHaveCount(1);
    expect($operation->input['intent']['known_hosts_content'])->toBe($this->node->wireguard_ip." ssh-ed25519 replacement-public-key\n");
});

it('admits each pin change immediately with the complete current snapshot', function (): void {
    $other = Node::withoutEvents(fn () => FleetFixtures::node('unchanged-pinned-peer'));
    $this->node->update(['ssh_host_key' => 'first-public-key']);
    $first = Operation::query()->sole();

    $this->node->update(['ssh_host_key' => 'second-public-key']);

    $second = Operation::query()->latest('id')->firstOrFail();
    expect($second->id)->not->toBe($first->id);
    expect($first->refresh()->status->terminal())->toBeFalse();
    expect($this->socket->sent)->toHaveCount(2);
    expect($first->input['intent']['known_hosts_content'])->toContain('first-public-key', $other->wireguard_ip.' ssh-ed25519 '.$other->ssh_host_key);
    expect($second->input['intent']['known_hosts_content'])->toContain('second-public-key', $other->wireguard_ip.' ssh-ed25519 '.$other->ssh_host_key);
    expect($second->input['intent']['known_hosts_content'])->not->toContain('first-public-key');

    foreach ([$second, $first] as $operation) {
        $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['known_hosts_ready' => true, 'digest' => $operation->input['intent']['digest'], 'changed' => true, 'path' => '/home/fixture/.ssh/known_hosts']];
        app(WorkerKnownHostsProjection::class)->apply($operation, $data);
        app(WorkerKnownHostsProjection::class)->apply($operation, $data);
        expect($operation->fresh()->result['digest'])->toBe($operation->input['intent']['digest']);
    }
    expect(Operation::query()->count())->toBe(2);
    expect($this->socket->sent)->toHaveCount(2);
});

it('does not suppress an explicit publication after a failed or identical snapshot', function (): void {
    $operation = app(AdmitWorkerKnownHostsAction::class)->execute();
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed',
        'error_code' => 'operation.native_failed', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => []];

    app(WorkerKnownHostsProjection::class)->apply($operation, $data);
    app(WorkerKnownHostsProjection::class)->apply($operation, $data);

    expect(Operation::query()->count())->toBe(1);
    expect(app(AdmitWorkerKnownHostsAction::class)->execute()->id)->not->toBe($operation->id);
    expect(Operation::query()->count())->toBe(2);
    expect($this->socket->sent)->toHaveCount(2);
});

it('admits a complete pinned Node at first insertion and skips incomplete new Nodes', function (): void {
    FleetFixtures::node('without-pin', managed: false);
    expect(Operation::query()->count())->toBe(0);
    $node = FleetFixtures::node('pinned-on-insert', platform: 'macos');
    expect(Operation::query()->sole()->input['intent']['known_hosts_content'])->toContain($node->wireguard_ip.' ssh-ed25519 '.$node->ssh_host_key);
    expect($this->socket->sent)->toHaveCount(1);
});

it('isolates automatic publication from the caller Operation idempotency scope', function (): void {
    $scope = app(OperationIdempotency::class);
    $scope->kind = 'node.ssh_access_converge';
    $scope->key = 'original-caller';
    $scope->fingerprint = 'original-fingerprint';
    $operation = app(AdmitWorkerKnownHostsAction::class)->execute();
    expect($operation->idempotency_key)->toBeNull();
    expect($scope->kind)->toBe('node.ssh_access_converge');
    expect($scope->key)->toBe('original-caller');
    expect($scope->fingerprint)->toBe('original-fingerprint');
});

it('publishes public pins from the TaskVM enrollment observation path', function (): void {
    $node = $this->node;
    $vm = new TaskVm(['name' => $node->name]);
    app(TaskVmEnrollmentProjection::class)->recordPublic($vm, ['guest_node_id' => $node->id], [
        'node' => ['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'new-task-vm-pin', 'ssh_host_fingerprint' => 'SHA256:public'],
    ]);
    expect(Operation::query()->sole()->input['intent']['known_hosts_content'])->toContain('new-task-vm-pin');
    expect($this->socket->sent)->toHaveCount(1);
});
