<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class RemoveFirewallRuleAction
{
    public function __construct(private StartOperationAction $operations) {}

    public function execute(FirewallRule $rule, ?Node $caller = null, ?string $requestId = null): Operation
    {
        $rule->loadMissing('node');
        if ($rule->node->platform !== 'linux') {
            throw new FirewallOperationException('platform', 'firewall.platform_unsupported', 'Firewall rules require a Linux node.', status: 422);
        }

        return DB::transaction(function () use ($rule, $caller, $requestId): Operation {
            $operation = $this->operations->execute('firewall.remove', 'node_firewall', $rule->node_id, $rule->node_id, [
                'rule_id' => $rule->id, 'node_id' => $rule->node_id, 'name' => $rule->name,
                'action' => $rule->action->value, 'source' => $rule->source, 'protocol' => $rule->protocol,
                'port' => $rule->port, 'caller_node_id' => $caller?->id,
            ], 'operator', $requestId);
            $rule->update(['status' => LifecycleStatus::Removing, 'failed_step' => null, 'error_code' => null]);

            return $operation;
        });
    }
}
