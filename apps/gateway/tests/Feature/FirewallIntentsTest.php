<?php

declare(strict_types=1);

use App\Actions\Firewall\RemoveFirewallRuleAction;
use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallIntentProjection;
use App\Domain\Firewall\FirewallMutationInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->node = Node::query()->forceCreate(['id' => 9, 'architecture' => 'x86_64', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'name' => 'private-dev', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active]);
    $this->node->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->rule = FirewallRule::query()->create(['node_id' => $this->node->id, 'name' => 'canary', 'action' => 'allow', 'source' => '192.0.2.100', 'protocol' => 'tcp', 'port' => '45678', 'status' => LifecycleStatus::Provisioning]);
    $this->input = ['rule_id' => $this->rule->id, 'node_id' => $this->node->id, 'name' => 'canary', 'action' => 'allow', 'source' => '192.0.2.100', 'protocol' => 'tcp', 'port' => '45678'];
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'firewall.allow', 'target_type' => 'node_firewall', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'state' => ['swamp_run_id' => $this->runId], 'input' => [...$this->input, 'firewall' => app(FirewallMutationInput::class)->capture($this->rule)]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->body = ['run_id' => $this->runId, 'started_at' => '2026-10-31T08:00:00.000Z'];
});

it('returns admitted rule data to its exact Node without executable agent metadata', function (): void {
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.intent.rule.name', 'canary')->assertJsonPath('data.input.intent.ssh.target.host_keys', ['ssh-ed25519 fixture-host-key'])->assertJsonPath('data.input.execution_secrets', []);
    $foreign = Node::query()->create(['name' => 'foreign', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.10', 'status' => LifecycleStatus::Active]);
    $gateway = Node::query()->where('name', 'gateway')->firstOrFail();
    $foreign->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
});

it('records the native outcome and redacts undeclared result fields without validating execution again', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'running'])->assertOk();
    $finished = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => ['backend' => 'active', 'password' => 'discard-this']];
    $this->postJson($this->path.'/result', $finished)->assertOk()->assertJsonPath('data.result', ['backend' => 'active']);
    $this->postJson($this->path.'/result', $finished)->assertOk();
    expect($this->rule->fresh()->status)->toBe(LifecycleStatus::Active);
    expect($this->operation->fresh()->state)->not->toHaveKeys(['steps', 'executor_runs']);
});

it('projects safe failure without querying deleted step receipts', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'finished_at' => '2026-10-31T08:00:01.000Z', 'error_code' => 'firewall.apply_failed', 'result' => ['error_code' => 'firewall.apply_failed']])->assertOk();
    expect($this->rule->fresh())->status->toBe(LifecycleStatus::Failed)->error_code->toBe('firewall.apply_failed')->failed_step->toBeNull();
});

it('deletes only the admitted managed rule on native removal and accepts duplicate terminal delivery', function (): void {
    $this->operation->update(['kind' => 'firewall.remove']);
    $body = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => ['backend' => 'absent']];
    $this->postJson($this->path.'/result', $body)->assertOk();
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->rule->fresh())->toBeNull();
});

it('binds the normal start before the own Node can read and records no agent digest', function (): void {
    config(['swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = function (array $request): array {
        $id = $request['payload']['inputs']['operation_id'];
        $this->getJson('/api/v1/direct-operations/'.$id.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-direct', 'runId' => $this->runId]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(StoreFirewallRuleAction::class)->execute($this->node, new StoreFirewallRuleData('native-rule', FirewallAction::Allow, '192.0.2.101', 'tcp', '45679'));
    expect($operation->input)->not->toHaveKey('agent_sha256');
    expect($socket->sent)->toHaveCount(1);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk();
    expect(app(DirectOperation::class)->definition('firewall.allow'))->toBe(['workflow' => 'operation-firewall-direct', 'projection' => FirewallIntentProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'firewall', 'step' => 'result']]);
});

it('refuses another native root without changing the managed rule', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'run_id' => 'f4c20822-d85f-4212-a60d-a81a52c30baa', 'status' => 'succeeded', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => ['backend' => 'active']])->assertConflict();
    expect($this->rule->fresh()->status)->toBe(LifecycleStatus::Provisioning);
});

it('freezes rule and SSH intent before dispatch and refuses missing pins before persistence', function (): void {
    config(['swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $op = app(StoreFirewallRuleAction::class)->execute($this->node, new StoreFirewallRuleData('immutable', FirewallAction::Allow, '192.0.2.101', 'udp', '45680'));
    expect($socket->sent[0]['payload']['inputs']['intent'])->toBe($op->input['firewall']);
    $this->node->update(['wireguard_ip' => '10.44.0.99', 'ssh_host_key' => null]);
    expect(app(FirewallIntentProjection::class)->input($op)['intent']['ssh']['target']['host'])->toBe('10.44.0.9');
    $before = FirewallRule::query()->count();
    expect(fn () => app(StoreFirewallRuleAction::class)->execute($this->node, new StoreFirewallRuleData('missing-pin', FirewallAction::Allow, 'any', 'tcp', '45681')))
        ->toThrow(ResourceOperationException::class);
    expect(FirewallRule::query()->count())->toBe($before)->and($socket->sent)->toHaveCount(1);
});

it('captures other named contributions before removal without reading a later catalog', function (): void {
    config(['swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $alias = FirewallRule::query()->create([
        'node_id' => $this->node->id, 'name' => 'shared', 'action' => 'allow',
        'source' => $this->rule->source, 'protocol' => 'tcp', 'port' => '45678:45680',
        'status' => LifecycleStatus::Active,
    ]);
    $operation = app(RemoveFirewallRuleAction::class)->execute($this->rule);
    $captured = $operation->input['firewall'];
    expect($captured['ensure'])->toBe('absent')
        ->and($captured['survivors'])->toHaveCount(1)
        ->and($captured['survivors'][0]['name'])->toBe('shared')
        ->and($captured['survivors'][0]['port'])->toBe('45678:45680');
    $alias->delete();
    expect(app(FirewallIntentProjection::class)->input($operation)['intent'])->toBe($captured);
});

it('lowers a range to immutable official numeric tuples and retains overlapping named contributions', function (): void {
    $this->rule->update(['port' => '45678:45680']);
    FirewallRule::query()->create([
        'node_id' => $this->node->id, 'name' => 'other-name', 'action' => 'allow',
        'source' => $this->rule->source, 'protocol' => 'tcp', 'port' => '45679',
        'status' => LifecycleStatus::Active,
    ]);

    $input = app(FirewallMutationInput::class)->capture($this->rule, true);

    expect($input['tuples'])->toBe([
        ['port' => 45678, 'ensure' => 'absent'],
        ['port' => 45679, 'ensure' => 'present'],
        ['port' => 45680, 'ensure' => 'absent'],
    ])->and($input['legacy_remove'])->toBe([
        '/usr/sbin/ufw', '--force', 'delete', 'allow', 'in', 'from', '192.0.2.100',
        'to', 'any', 'port', '45678:45680', 'proto', 'tcp', 'comment', 'orbit:node:9:firewall:canary',
    ]);
});

it('projects each kind from the fixed native history stdout without a Gateway callback', function (string $kind): void {
    $this->operation->update(['kind' => $kind]);
    config(['swamp.read_token' => 'fixture-read-token']);
    $backend = $kind === 'firewall.remove' ? 'absent' : 'active';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $this->runId, 'workflowName' => 'operation-firewall-direct', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $this->operation->id, 'node_id' => $this->node->id, 'kind' => $kind],
        'startedAt' => '2026-11-01T06:00:00Z', 'completedAt' => '2026-11-01T06:00:01Z',
        'jobs' => [['name' => 'firewall', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode(['backend' => $backend], JSON_THROW_ON_ERROR), 'exitCode' => 0]]]]],
    ]]]];
    app()->instance(WebSocketClient::class, $socket);

    $definition = app(DirectOperation::class)->definition($kind);
    $projected = app(SwampCommandStep::class)->projectTerminal(
        $this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $definition['terminal_result'],
    );

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['backend' => $backend]);
    expect($socket->sent)->toHaveCount(1);
    if ($kind === 'firewall.remove') {
        $this->assertModelMissing($this->rule);
    } else {
        expect($this->rule->fresh()->status)->toBe(LifecycleStatus::Active);
    }
})->with(['firewall.allow', 'firewall.deny', 'firewall.remove']);

it('holds the shared Node firewall admission while a named contribution is being removed', function (): void {
    config(['swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $alias = FirewallRule::query()->create([
        'node_id' => $this->node->id, 'name' => 'alias', 'action' => 'allow',
        'source' => $this->rule->source, 'protocol' => 'tcp', 'port' => $this->rule->port,
        'status' => LifecycleStatus::Active,
    ]);
    $first = app(RemoveFirewallRuleAction::class)->execute($this->rule);

    expect(fn () => app(RemoveFirewallRuleAction::class)->execute($alias))
        ->toThrow(ResourceOperationException::class, 'Another operation on this target has not ended. Follow it, or cancel it, before you start another.');
    expect($alias->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and($first->input['firewall']['tuples'])->toBe([['port' => 45678, 'ensure' => 'present']]);
    expect($socket->sent)->toHaveCount(1);
});

it('retains a tuple still required by the canonical Node firewall catalog', function (): void {
    $this->rule->update(['port' => '22', 'source' => 'any']);

    $input = app(FirewallMutationInput::class)->capture($this->rule, true);

    expect($input['tuples'])->toBe([['port' => 22, 'ensure' => 'present']]);
    expect(array_column($input['managed_survivors'], 'comment'))->toContain('orbit:public-ssh-recovery');
});
