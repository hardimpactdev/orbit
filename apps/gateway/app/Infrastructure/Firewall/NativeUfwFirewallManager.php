<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\FirewallPort;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\FirewallRule;
use App\Models\Node;
use Throwable;

final readonly class NativeUfwFirewallManager implements FirewallManager
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function converge(FirewallRule $rule): FirewallBackendStatus
    {
        $node = $this->eligibleNode($rule);
        $this->guardRecoverySsh($rule, $node);

        return $this->run($rule, $node, 'converge');
    }

    public function remove(FirewallRule $rule): FirewallBackendStatus
    {
        return $this->run($rule, $this->eligibleNode($rule), 'remove');
    }

    private function run(FirewallRule $rule, Node $node, string $operation): FirewallBackendStatus
    {
        $this->requireAddress($node);
        try {
            $result = $this->agents->run($node, 'firewall.rule', [
                'node_id' => $node->id,
                'name' => $rule->name,
                'action' => $rule->action->value,
                'source' => $rule->source,
                'port' => $rule->port,
                'protocol' => $rule->protocol,
                'operation' => $operation,
            ]);

            if (! is_string($result['backend'] ?? null)) {
                throw new \UnexpectedValueException('Invalid firewall observation.');
            }

            return FirewallBackendStatus::from($result['backend']);
        } catch (AgentCommandRefused $refusal) {
            $step = match ($refusal->errorCode) {
                'firewall.rule_collision' => 'ownership',
                'firewall.status_failed', 'firewall.status_unrecognized' => 'status',
                'firewall.reconcile_failed' => 'reconcile',
                'firewall.verify_failed' => 'verify',
                'firewall.remove_verify_failed' => 'verify-remove',
                default => $operation === 'remove' ? 'remove' : 'apply',
            };
            throw new FirewallOperationException($step, $refusal->errorCode, $refusal->getMessage(), previous: $refusal);
        } catch (Throwable $failure) {
            throw new FirewallOperationException('status', 'firewall.status_failed', "Could not run the firewall command on node [{$node->name}].", previous: $failure);
        }
    }

    private function guardRecoverySsh(FirewallRule $rule, Node $node): void
    {
        if ($rule->action !== FirewallAction::Deny || ! FirewallPort::contains($rule->port, $node->public_ssh_port)) {
            return;
        }

        throw new FirewallOperationException(
            step: 'recovery-ssh',
            errorCode: 'firewall.public_ssh_deny_forbidden',
            message: "Firewall rule [{$rule->name}] would deny the public recovery SSH port.",
        );
    }

    private function eligibleNode(FirewallRule $rule): Node
    {
        $node = $rule->node;

        if ($node->platform !== 'linux') {
            throw new FirewallOperationException(
                step: 'platform',
                errorCode: 'firewall.platform_unsupported',
                message: 'Firewall rules require a Linux node.',
                status: 422,
            );
        }

        return $node;
    }

    private function requireAddress(Node $node): void
    {
        $host = $node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            throw new FirewallOperationException(
                step: 'wireguard-address',
                errorCode: 'firewall.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }
    }
}
