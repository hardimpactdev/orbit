<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallInspectionBatchData;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Firewall\FirewallInspector;
use App\Domain\Firewall\FirewallRuleInspectionStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\Processes\CommandDeadline;

final readonly class NativeUfwFirewallInspector implements FirewallInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private AgentContract $contract,
        private CommandDeadline $deadline = new CommandDeadline,
    ) {}

    public function inspect(array $targets): FirewallInspectionBatchData
    {
        $node = $targets[0]->node;

        foreach ($targets as $target) {
            if (! $target->node->is($node)) {
                throw new DoctorInspectionException;
            }
        }

        $host = $node->wireguard_ip;
        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }
        try {
            $shapes = array_map(static fn (FirewallInspectionTarget $target): array => new UfwRuleShape(
                $target->shape->comment, $target->shape->action, $target->shape->direction,
                $target->shape->source, $target->shape->destination, $target->shape->port,
                $target->shape->protocol, $target->shape->inInterface, $target->shape->outInterface,
                $target->shape->family,
            )->toAgentInput(), $targets);
            $result = $this->agents->run($node, 'firewall.inspect', [
                'expected' => $shapes, 'exclusive_families' => [], 'stored' => false,
            ], $this->deadline->cap($this->contract->command('firewall.inspect')->timeoutSeconds));
            if (! is_string($result['backend'] ?? null)) {
                throw new DoctorInspectionException;
            }
            $backend = FirewallBackendStatus::from($result['backend']);
            if ($backend !== FirewallBackendStatus::Active) {
                return $this->uniformResult($backend, $targets);
            }
            if (! is_array($result['ownerships'] ?? null) || ! array_is_list($result['ownerships']) || count($result['ownerships']) !== count($targets)) {
                throw new DoctorInspectionException;
            }

            return new FirewallInspectionBatchData($backend, array_map(
                static fn (string $value): FirewallRuleInspectionStatus => FirewallRuleInspectionStatus::from($value),
                $result['ownerships'],
            ));
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }
    }

    /** @param non-empty-list<FirewallInspectionTarget> $targets */
    private function uniformResult(
        FirewallBackendStatus $backend,
        array $targets,
    ): FirewallInspectionBatchData {
        return new FirewallInspectionBatchData(
            $backend,
            array_fill(0, count($targets), FirewallRuleInspectionStatus::Missing),
        );
    }
}
