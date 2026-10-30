<?php

declare(strict_types=1);

use App\Actions\Firewall\RemoveFirewallRuleAction;
use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallIntentProjection;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FirewallOperationProof;

beforeEach(function (): void {
    config(['swamp.run_token' => 'fixture-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    $this->node = Node::query()->forceCreate([
        'id' => 209, 'architecture' => 'x86_64', 'user' => 'orbit',
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.3',
    ]);
});

function projectFirewallAllow(Operation $operation): void
{
    app(FirewallIntentProjection::class)->apply($operation->fresh(), [
        'run_id' => $operation->fresh()->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['backend' => 'active'],
    ]);
}

describe('FirewallRule record events', function (): void {
    it('broadcasts firewall.created when a new rule is stored', function (): void {
        Event::fake([RecordBroadcast::class]);

        $data = new StoreFirewallRuleData(
            name: 'private-web',
            action: FirewallAction::Allow,
            source: '192.0.2.0/24',
            protocol: 'tcp',
            port: '8443',
        );

        $operation = app(StoreFirewallRuleAction::class)->execute($this->node, $data);
        projectFirewallAllow($operation);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::FirewallCreated
                && $event->id === $operation->input['rule_id']
                && $event->data['name'] === 'private-web',
        );
    });

    it('does not broadcast when re-asserting an existing rule with identical configuration', function (): void {
        $data = new StoreFirewallRuleData(
            name: 'private-web',
            action: FirewallAction::Allow,
            source: '192.0.2.0/24',
            protocol: 'tcp',
            port: '8443',
        );
        projectFirewallAllow(app(StoreFirewallRuleAction::class)->execute($this->node, $data));

        Event::fake([RecordBroadcast::class]);

        $operation = app(StoreFirewallRuleAction::class)->execute($this->node, $data);
        projectFirewallAllow($operation);

        Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::FirewallCreated);
    });

    it('broadcasts firewall.deleted with a minimal snapshot when a rule is removed', function (): void {
        $rule = FirewallRule::query()->create([
            'node_id' => $this->node->id,
            'name' => 'private-web',
            'action' => FirewallAction::Allow,
            'source' => '192.0.2.0/24',
            'protocol' => 'tcp',
            'port' => '8443',
            'status' => LifecycleStatus::Active,
        ]);

        Event::fake([RecordBroadcast::class]);

        app(RemoveFirewallRuleAction::class)->execute($rule);
        FirewallOperationProof::finish('firewall.remove', 'test');

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::FirewallDeleted
                && $event->id === $rule->id
                && $event->data === ['id' => $rule->id, 'name' => 'private-web'],
        );
    });
});
