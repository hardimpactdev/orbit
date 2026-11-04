<?php

declare(strict_types=1);

use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

function firewallRetryNode(): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'firewall-target', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '192.0.2.10', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
}

function firewallRetryRule(Node $node, LifecycleStatus $status, FirewallAction $action = FirewallAction::Allow): FirewallRule
{
    return FirewallRule::query()->create([
        'node_id' => $node->id, 'action' => $action, 'port' => '8443', 'protocol' => 'tcp',
        'source' => '192.0.2.20', 'label' => 'office', 'status' => $status,
        'failed_step' => $status === LifecycleStatus::Failed ? 'apply' : null,
        'error_code' => $status === LifecycleStatus::Failed ? 'firewall.apply_failed' : null,
    ]);
}

it('starts a new operation for a failed allow rule without duplicating its record', function (): void {
    Http::preventStrayRequests();
    config(['swamp.run_token' => 'test-token']);
    $sent = null;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect')->once();
    $socket->shouldReceive('send')->once()->withArgs(function (array $message) use (&$sent): bool {
        $sent = $message;

        return $message['type'] === 'workflow.run';
    });
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('receive')->once()->andReturnUsing(function () use (&$sent): array {
        return [[
            'type' => 'event', 'id' => $sent['id'], 'event' => [
                'workflowName' => 'operation-firewall-direct', 'kind' => 'started',
                'runId' => '799ae332-75e2-41aa-a53e-843e297a1806',
            ],
        ]];
    });
    $socket->shouldReceive('close')->once();
    app()->instance(WebSocketClient::class, $socket);
    $node = firewallRetryNode();
    $rule = firewallRetryRule($node, LifecycleStatus::Failed);
    $data = new StoreFirewallRuleData(FirewallAction::Allow, '192.0.2.20', 'tcp', '8443', 'office');

    $operation = app(StoreFirewallRuleAction::class)->execute($node, $data);

    expect($operation->kind)->toBe('firewall.allow');
    expect($operation->input['rule_id'])->toBe($rule->id);
    expect($sent['payload']['workflowIdOrName'])->toBe('operation-firewall-direct');
    $this->assertDatabaseCount('firewall_rules', 1);
    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseHas('firewall_rules', [
        'id' => $rule->id, 'status' => 'provisioning', 'failed_step' => null, 'error_code' => null,
    ]);
    Http::assertNothingSent();
});

it('refuses a duplicate allow rule that has not failed', function (LifecycleStatus $status): void {
    $node = firewallRetryNode();
    $rule = firewallRetryRule($node, $status);
    $before = $rule->refresh()->getAttributes();
    $data = new StoreFirewallRuleData(FirewallAction::Allow, '192.0.2.20', 'tcp', '8443', 'office');

    expect(fn () => app(StoreFirewallRuleAction::class)->execute($node, $data))
        ->toThrow(ResourceOperationException::class, 'already has a firewall rule');

    expect($rule->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('firewall_rules', 1);
    $this->assertDatabaseCount('operations', 0);
})->with([LifecycleStatus::Active, LifecycleStatus::Provisioning, LifecycleStatus::Removing]);

it('refuses an opposite rule even when the existing rule has failed', function (): void {
    $node = firewallRetryNode();
    $rule = firewallRetryRule($node, LifecycleStatus::Failed, FirewallAction::Deny);
    $before = $rule->refresh()->getAttributes();
    $data = new StoreFirewallRuleData(FirewallAction::Allow, '192.0.2.20', 'tcp', '8443');

    expect(fn () => app(StoreFirewallRuleAction::class)->execute($node, $data))
        ->toThrow(ResourceOperationException::class, 'already has a deny rule');

    expect($rule->refresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('operations', 0);
});
