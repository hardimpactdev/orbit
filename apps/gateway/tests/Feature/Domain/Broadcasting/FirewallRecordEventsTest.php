<?php

declare(strict_types=1);

use App\Actions\Firewall\RemoveFirewallRuleAction;
use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.3',
    ]);
});

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
        $executor = app(SwampOperationExecutor::class);
        $executor->complete($executor->claim('firewall.allow', 'test'), ['worker' => 'test', 'status' => 'succeeded']);

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
        app(StoreFirewallRuleAction::class)->execute($this->node, $data);
        $executor = app(SwampOperationExecutor::class);
        $executor->complete($executor->claim('firewall.allow', 'first'), ['worker' => 'first', 'status' => 'succeeded']);

        Event::fake([RecordBroadcast::class]);

        $operation = app(StoreFirewallRuleAction::class)->execute($this->node, $data);
        $executor = app(SwampOperationExecutor::class);
        $executor->complete($executor->claim('firewall.allow', 'test'), ['worker' => 'test', 'status' => 'succeeded']);

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
        $executor = app(SwampOperationExecutor::class);
        $executor->complete($executor->claim('firewall.remove', 'test'), ['worker' => 'test', 'status' => 'succeeded']);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::FirewallDeleted
                && $event->id === $rule->id
                && $event->data === ['id' => $rule->id, 'name' => 'private-web'],
        );
    });
});
