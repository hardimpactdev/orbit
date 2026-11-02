<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Actions\Operations\StartOperationAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallMutationInput;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\FirewallProtectedPorts;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class StoreFirewallRuleAction
{
    public function __construct(
        private StartOperationAction $operations,
    ) {}

    public function execute(Node $node, StoreFirewallRuleData $data, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($node, $data, $caller, $requestId): Operation {
            $this->guardLinuxPlatform($node);
            if ($data->action === FirewallAction::Deny) {
                app(FirewallProtectedPorts::class)->guard($node, $data->port);
            }
            $rule = $this->identity($node, $data);
            app(DirectOperation::class)->writer('firewall.'.$data->action->value, [], $node->id);
            $rule->fill([
                'action' => $data->action, 'source' => $data->source,
                'protocol' => $data->protocol, 'port' => $data->port, 'label' => $data->label,
                'status' => LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null,
            ])->save();
            $rule->setRelation('node', $node);

            return $this->operations->execute('firewall.'.$data->action->value, 'node_firewall', $node->id, $node->id, [
                'rule_id' => $rule->id, 'label' => $rule->label, 'action' => $rule->action->value,
                'source' => $rule->source, 'protocol' => $rule->protocol, 'port' => $rule->port,
                'node_id' => $node->id, 'caller_node_id' => $caller?->id,
                'firewall' => app(FirewallMutationInput::class)->capture($rule),
            ], 'operator', $requestId, lockKey: OperationStore::lockKey('node_firewall', $node->id));
        });
    }

    /** A Node holds one rule per (port, protocol, source); the opposite action on it conflicts. */
    private function identity(Node $node, StoreFirewallRuleData $data): FirewallRule
    {
        $same = FirewallRule::query()->where('node_id', $node->id)->where('port', $data->port)->where('source', $data->source)->get();
        foreach ($same as $rule) {
            if ($rule->protocol === $data->protocol) {
                if ($rule->action !== $data->action) {
                    throw new ResourceOperationException('firewall.rule_conflict',
                        "Node [{$node->name}] already has a {$rule->action->value} rule for {$rule->identity()}.", 409);
                }
                throw new ResourceOperationException('firewall.rule_exists',
                    "Node [{$node->name}] already has a firewall rule for {$rule->identity()}.", 409);
            }
            if ($rule->protocol === 'any' || $data->protocol === 'any') {
                throw new ResourceOperationException('firewall.rule_overlap',
                    "Firewall rule {$rule->identity()} overlaps {$data->port}/{$data->protocol} from {$data->source}.", 409);
            }
        }

        return new FirewallRule(['node_id' => $node->id]);
    }

    private function guardLinuxPlatform(Node $node): void
    {
        if ($node->platform === 'linux') {
            return;
        }

        throw new FirewallOperationException(
            step: 'platform',
            errorCode: 'firewall.platform_unsupported',
            message: 'Firewall rules require a Linux node.',
            status: 422,
        );
    }
}
