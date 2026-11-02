<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeSshAccessProjection;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\NodeFootprint;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->gateway = $this->markAsGateway(FleetFixtures::node('ssh-policy-gateway', [RoleName::Gateway]));
    $this->target = FleetFixtures::node('ssh-policy-target');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fake.run.token']);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->path = "/api/v1/nodes/{$this->target->id}/ssh-access/converge";
});

function sshAccessCompletion(Operation $operation, string $status = 'succeeded', array $result = ['ssh_access_ready' => true]): array
{
    return ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'error_code' => $status === 'failed' ? 'swamp.run_failed' : null, 'result' => $result];
}

it('admits the fixed tracked public key and existing execution copy without a host call', function (): void {
    $this->withHeader('Idempotency-Key', 'ssh-access-once')->post($this->path, [], ['Accept' => 'application/json'])
        ->assertAccepted()->assertJsonPath('data.kind', 'node.ssh_access_converge');
    $operation = Operation::query()->sole();
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect($this->socket->sent)->toHaveCount(1);
    $payload = $this->socket->sent[0]['payload'];
    expect($payload['workflowIdOrName'])->toBe('operation-node-ssh-access-converge');
    expect($payload['inputs'])->toMatchArray(['node_id' => $this->target->id, 'kind' => 'node.ssh_access_converge',
        'execution_secrets' => ['bootstrap_identity' => ['vault' => 'orbit-control-inputs', 'key' => 'bootstrap-identity-node-14']]]);
    expect($payload['inputs']['intent'])->toMatchArray(['control_node_id' => 209,
        'identity_file' => '/etc/orbit/swamp-role-worker/ssh-identity',
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND',
        'target' => ['name' => 'target', 'host' => $this->target->wireguard_ip, 'user' => 'orbit', 'port' => 22,
            'host_keys' => [$this->target->ssh_host_key_type.' '.$this->target->ssh_host_key]]]);
    expect($operation->input['participant_node_ids'])->toBe([209, $this->target->id]);
    expect($operation->node_id)->toBe($this->target->id);
    expect(NodeFootprint::query()->exists())->toBeFalse();
});

it('rejects caller-selected keys targets and executable inputs', function (string $field): void {
    $this->postJson($this->path, [$field => 'untrusted'])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(Operation::query()->exists())->toBeFalse();
    expect($this->socket->sent)->toBe([]);
})->with(['public_key', 'target', 'execution_secrets', 'script']);

it('requires an active peer and directed access before admission', function (string $caller): void {
    $this->withServerVariables(['REMOTE_ADDR' => $caller === 'unknown' ? '192.0.2.99' : FleetFixtures::node('foreign-peer')->wireguard_ip]);
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertForbidden();
    expect(Operation::query()->exists())->toBeFalse();
    expect($this->socket->sent)->toBe([]);
})->with(['unknown', 'foreign']);

it('requires complete stored pins before submission', function (): void {
    $this->target->update(['ssh_host_key' => null]);
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertServiceUnavailable()->assertJsonPath('error.code', 'swamp.ssh_target_unavailable');
    expect(Operation::query()->exists())->toBeFalse();
    expect($this->socket->sent)->toBe([]);
});

it('records only its footprint contribution after native own-key verification and does not repeat publication', function (): void {
    $this->freezeTime();
    $before = $this->target->fresh()->getAttributes();
    NodeFootprint::query()->create(['node_id' => $this->target->id, 'artifacts' => ['agent' => 'prior-agent'], 'digest' => 'prior', 'cli_state' => 'managed']);
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->sole();
    $data = sshAccessCompletion($operation, result: ['ssh_access_ready' => true, 'private_key' => 'not-a-public-result']);
    $projection = app(NodeSshAccessProjection::class);
    $projection->apply($operation, $data);
    $first = NodeFootprint::query()->sole()->getAttributes();
    $projection->apply($operation, $data);
    expect(NodeFootprint::query()->sole()->getAttributes())->toBe($first);
    expect($this->target->fresh()->getAttributes())->toBe($before);
    expect(NodeFootprint::query()->sole()->artifacts)->toBe(['agent' => 'prior-agent']);
    expect(NodeFootprint::query()->sole()->digest)->toBe('prior');
    expect(NodeFootprint::query()->sole()->converged_at)->toBeNull();
    expect(NodeFootprint::query()->sole()->ssh_access_digest)->toBe($operation->input['intent']['digest']);
    expect(NodeFootprint::query()->sole()->cli_state)->toBe('managed');
    expect($operation->fresh()->result)->toBe(['ssh_access_ready' => true]);
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect(Operation::query()->count())->toBe(1);
    expect($this->socket->sent)->toHaveCount(1);
});

it('does not mark the footprint ready for a failed native run', function (): void {
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertAccepted();
    $operation = Operation::query()->sole();
    app(NodeSshAccessProjection::class)->apply($operation, sshAccessCompletion($operation, 'failed'));
    expect(NodeFootprint::query()->exists())->toBeFalse();
    expect($operation->fresh()->status->value)->toBe('failed');
});

it('refuses the root account before submission', function (): void {
    $this->target->update(['user' => 'root']);
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('error.code', 'node.ssh_account_invalid');
    expect(Operation::query()->exists())->toBeFalse();
    expect($this->socket->sent)->toBe([]);
});

it('captures a changed pinned target as a new publication rather than reusing another host receipt', function (): void {
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertAccepted();
    $first = Operation::query()->sole();
    app(NodeSshAccessProjection::class)->apply($first, sshAccessCompletion($first));
    $this->target->update(['wireguard_ip' => '10.44.0.199', 'ssh_host_key' => 'new-host-key']);
    $this->post($this->path, [], ['Accept' => 'application/json'])->assertAccepted();
    expect(Operation::query()->count())->toBe(2);
    expect($this->socket->sent)->toHaveCount(2);
});
