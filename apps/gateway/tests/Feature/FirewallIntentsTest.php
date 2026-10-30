<?php

declare(strict_types=1);

use App\Actions\Firewall\RemoveFirewallRuleAction;
use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Actions\Operations\StartOperationAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'swamp', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->node = Node::query()->forceCreate(['id' => 209, 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'name' => 'private-dev', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active]);
    $this->rule = FirewallRule::query()->create(['node_id' => $this->node->id, 'name' => 'canary', 'action' => 'allow', 'source' => '192.0.2.100', 'protocol' => 'tcp', 'port' => '45678', 'status' => LifecycleStatus::Provisioning]);
    $this->input = ['rule_id' => $this->rule->id, 'node_id' => $this->node->id, 'name' => 'canary', 'action' => 'allow', 'source' => '192.0.2.100', 'protocol' => 'tcp', 'port' => '45678'];
    $this->operation = Operation::query()->create(['kind' => 'firewall.allow', 'target_type' => 'node_firewall', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'state' => ['firewall_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'input' => $this->input]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->body = ['run_id' => $this->runId, 'started_at' => '2026-10-30T12:00:00.000Z'];
});

it('reads only admitted intent and requires the existing executor identity', function (): void {
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', array_diff_key($this->input, array_flip(['rule_id'])));
    expect($this->operation->fresh()->state)->toBe(['firewall_writer' => 'direct', 'swamp_run_id' => $this->runId]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
});

it('projects status and the rule without a Gateway executor receipt', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'running'])->assertOk();
    $finished = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['backend' => 'active', 'password' => 'discard-this']];
    $this->postJson($this->path.'/result', $finished)->assertOk()->assertJsonPath('data.result', ['backend' => 'active']);
    $this->postJson($this->path.'/result', $finished)->assertOk();
    expect($this->rule->fresh()->status)->toBe(LifecycleStatus::Active);
    expect($this->operation->fresh()->state)->toHaveKeys(['firewall_writer', 'swamp_run_id', 'steps'])->not->toHaveKeys(['executor_runs', 'executor_lease_node_id']);
    expect($this->operation->fresh()->worker)->toBeNull();
    expect($this->operation->fresh()->attempts)->toBe(0);
});

it('refuses mismatched results input overrides and other runs', function (): void {
    $body = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z'];
    $this->postJson($this->path.'/result', [...$body, 'result' => ['backend' => 'absent']])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$body, 'node_id' => $this->worker->id, 'result' => ['backend' => 'active']])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$body, 'result' => ['backend' => 'active', 'error_code' => 'firewall.apply_failed']])->assertUnprocessable();
    $this->operation->update(['state' => ['firewall_writer' => 'direct', 'swamp_run_id' => 'f4c20822-d85f-4212-a60d-a81a52c30baa']]);
    $this->postJson($this->path.'/result', [...$body, 'result' => ['backend' => 'active']])->assertConflict();
});

it('projects refusal and removal only with their corresponding native results', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'finished_at' => '2026-10-30T12:00:01.000Z', 'error_code' => 'firewall.apply_failed', 'result' => ['error_code' => 'firewall.apply_failed']])->assertOk();
    expect($this->rule->fresh()->status)->toBe(LifecycleStatus::Failed);
    $remove = Operation::query()->create(['kind' => 'firewall.remove', 'target_type' => 'node_firewall', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'state' => ['firewall_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'input' => $this->input]);
    $path = '/api/v1/direct-operations/'.$remove->id.'/result';
    $body = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['backend' => 'absent']];
    $this->postJson($path, $body)->assertOk();
    $this->postJson($path, $body)->assertOk();
    expect($this->rule->fresh())->toBeNull();
});

it('excludes direct intents from discovery claims and heartbeat writes', function (): void {
    $response = $this->getJson('/api/v1/operation-executor/claimable')->assertOk();
    expect(collect($response->json('data.kinds'))->firstWhere('kind', 'firewall.allow'))->toBeNull();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'firewall.allow', 'worker' => 'old', 'operation_id' => $this->operation->id])->assertUnprocessable();
    $this->postJson('/api/v1/operation-executor/'.$this->operation->id.'/heartbeat', ['worker' => 'old'])->assertUnprocessable();
});

it('selects direct Firewall kinds on the proven Node', function (): void {
    expect(app(DirectOperation::class)->writer('firewall.allow', [], 209))->toBe('direct');
    expect(app(DirectOperation::class)->writer('firewall.deny', [], 209))->toBe('direct');
    expect(app(OperationKinds::class)->directOnly('firewall.deny'))->toBeTrue();
    expect(app(DirectOperation::class)->writer('firewall.remove', [], 209))->toBe('direct');
    expect(app(OperationKinds::class)->directOnly('firewall.remove'))->toBeTrue();
    expect(app(OperationKinds::class)->directOnly('firewall.allow'))->toBeTrue();
    $this->operation->update(['state' => []]);
    config(['swamp.direct_writers' => ['firewall.allow' => 'direct']]);
    expect(DirectOperation::owns($this->operation))->toBeFalse();
    expect(app(DirectOperation::class)->writer('firewall.deny', [], 209))->toBe('direct');
    $this->getJson($this->path.'?run_id='.$this->runId)->assertUnprocessable();
});

it('starts after commit without a target hold and retains its admitted writer', function (): void {
    config(['swamp.direct_writers' => ['firewall.allow' => 'direct'], 'swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-direct', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    DB::beginTransaction();
    $operation = app(StartOperationAction::class)->execute('firewall.allow', 'node_firewall', $this->node->id, $this->node->id, $this->input, 'operator', null);
    expect($operation->lock_key)->toBeNull();
    expect($socket->sent)->toBe([]);
    DB::commit();
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-firewall-direct');
    expect($socket->sent[0]['payload']['inputs'])->toBe(['operation_id' => $operation->id, 'kind' => 'firewall.allow', 'node_id' => $this->node->id]);
    config(['swamp.direct_writers' => ['firewall.allow' => 'polled']]);
    expect(DirectOperation::owns($operation->fresh()))->toBeTrue();
});

it('leaves uncertain starts visible without fallback or a lease', function (): void {
    config(['swamp.direct_writers' => ['firewall.allow' => 'direct'], 'swamp.run_token' => 'fixture-token']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket(false));
    expect(fn () => app(StartOperationAction::class)->execute('firewall.allow', 'node_firewall', $this->node->id, $this->node->id, $this->input, 'operator', null))->toThrow(ResourceOperationException::class);
    $operation = Operation::query()->latest('id')->firstOrFail();
    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);
    expect($operation->fresh()->worker)->toBeNull();
    expect($operation->fresh()->state)->not->toHaveKeys(['executor_runs', 'executor_lease_node_id']);
});

it('refuses the first worker read until the Gateway receives and binds the root start', function (): void {
    config(['swamp.direct_writers' => ['firewall.allow' => 'direct'], 'swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = function (array $request): array {
        $id = $request['payload']['inputs']['operation_id'];
        $this->getJson('/api/v1/direct-operations/'.$id.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-direct', 'runId' => $this->runId]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(StartOperationAction::class)->execute('firewall.allow', 'node_firewall', $this->node->id, $this->node->id, $this->input, 'operator', null);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk();
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->runId);
});

it('allows the admitted Node peer and refuses a different Node worker', function (): void {
    config(['operation_executor.worker_node_ids' => [$this->node->id, $this->worker->id]]);
    $gateway = Node::query()->where('name', 'gateway')->firstOrFail();
    $this->node->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
    config(['dependency_detection.services_node_id' => null]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
});

it('declares no Gateway execution primitive for Firewall', function (): void {
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/primitive', ['run_id' => $this->runId])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('pins the admitted agent for a direct Firewall action without a Gateway relay', function (string $verb): void {
    $kind = 'firewall.'.$verb;
    config(['swamp.direct_writers' => [$kind => 'direct'], 'swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-direct', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = $verb === 'remove'
        ? app(RemoveFirewallRuleAction::class)->execute($this->rule)
        : app(StoreFirewallRuleAction::class)->execute($this->node, new StoreFirewallRuleData('sudo-'.$verb, FirewallAction::from($verb), '192.0.2.101', 'tcp', '45679'));

    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect($operation->state['firewall_writer'])->toBe('direct');
    expect($operation->lock_key)->toBeNull();
    expect($socket->sent)->toHaveCount(1);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.agent_sha256', $operation->input['agent_sha256']);
    config(['swamp.direct_writers' => [$kind => 'polled']]);
    expect($operation->fresh()->input['agent_sha256'])->toBe($operation->input['agent_sha256']);
})->with(['allow', 'deny', 'remove']);

it('refuses unavailable allow before recording intent', function (): void {
    config(['swamp.direct_writers' => ['firewall.allow' => 'direct']]);

    $this->node->forceFill(['id' => 210]);
    expect(fn () => app(StoreFirewallRuleAction::class)->execute($this->node, new StoreFirewallRuleData('foreign', FirewallAction::Allow, '192.0.2.102', 'tcp', '45680')))
        ->toThrow(ResourceOperationException::class, 'requires its direct Swamp writer');
    $this->node->forceFill(['id' => 209]);
    config(['swamp.direct_writers' => ['firewall.allow' => 'polled']]);
    expect(fn () => app(StoreFirewallRuleAction::class)->execute($this->node, new StoreFirewallRuleData('disabled', FirewallAction::Allow, '192.0.2.103', 'tcp', '45681')))
        ->toThrow(ResourceOperationException::class, 'requires its direct Swamp writer');
    expect(FirewallRule::query()->count())->toBe(1);
    expect(Operation::query()->count())->toBe(1);
});
