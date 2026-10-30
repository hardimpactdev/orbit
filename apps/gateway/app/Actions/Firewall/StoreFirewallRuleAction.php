<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Actions\Operations\StartOperationAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\FirewallPort;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
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
            $this->guardRecoverySsh($node, $data);
            $this->guardActionConflict($node, $data);
            $rule = FirewallRule::query()->firstOrNew([
                'node_id' => $node->id,
                'name' => $data->name,
            ]);
            $created = ! $rule->exists;
            $attributes = [
                'action' => $data->action,
                'source' => $data->source,
                'protocol' => $data->protocol,
                'port' => $data->port,
            ];

            if ($rule->exists && ! $this->matches($rule, $attributes)) {
                throw new ResourceOperationException(
                    errorCode: 'firewall.name_taken',
                    message: "Firewall rule [{$data->name}] already exists with different configuration.",
                    status: 409,
                );
            }

            $rule->fill([
                ...$attributes,
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();
            $rule->setRelation('node', $node);

            $writer = app(DirectOperation::class)->writer('firewall.'.$data->action->value, [], $node->id);

            return $this->operations->execute('firewall.'.$data->action->value, 'node_firewall', $node->id, $node->id, [
                'rule_id' => $rule->id, 'created' => $created, 'name' => $rule->name, 'action' => $rule->action->value,
                'source' => $rule->source, 'protocol' => $rule->protocol, 'port' => $rule->port,
                'node_id' => $node->id, 'caller_node_id' => $caller?->id,
                ...($writer === 'direct' ? ['agent_sha256' => app(AgentDelivery::class)->pin($node)] : []),
            ], 'operator', $requestId, expectedWriter: $writer);
        });
    }

    /** @param array{action: FirewallAction, source: string, protocol: string, port: string} $attributes */
    private function matches(FirewallRule $rule, array $attributes): bool
    {
        return
            $rule->action === $attributes['action']
            && $rule->source === $attributes['source']
            && $rule->protocol === $attributes['protocol']
            && $rule->port === $attributes['port'];
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

    private function guardRecoverySsh(Node $node, StoreFirewallRuleData $data): void
    {
        if (
            $data->action !== FirewallAction::Deny
            || ! FirewallPort::contains($data->port, $node->public_ssh_port)
        ) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'firewall.public_ssh_deny_forbidden',
            message: "Firewall rule [{$data->name}] would deny the public recovery SSH port.",
        );
    }

    private function guardActionConflict(Node $node, StoreFirewallRuleData $data): void
    {
        $conflict = FirewallRule::query()
            ->where('node_id', $node->id)
            ->where('name', '!=', $data->name)
            ->where('action', $this->oppositeAction($data->action))
            ->where('source', $data->source)
            ->where('protocol', $data->protocol)
            ->where('port', $data->port)
            ->first();

        if ($conflict === null) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'firewall.action_conflict',
            message: "Firewall rule [{$data->name}] conflicts with [{$conflict->name}] on the same source, protocol, and port.",
            status: 409,
        );
    }

    private function oppositeAction(FirewallAction $action): FirewallAction
    {
        return match ($action) {
            FirewallAction::Allow => FirewallAction::Deny,
            FirewallAction::Deny => FirewallAction::Allow,
        };
    }
}
