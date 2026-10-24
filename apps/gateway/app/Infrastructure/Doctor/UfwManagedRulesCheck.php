<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;

final readonly class UfwManagedRulesCheck
{
    /**
     * @param  list<UfwManagedRule>  $rules
     * @param  list<string>  $exclusiveFamilies
     */
    public function matches(AgentCommandRunner $agents, Node $node, array $rules, array $exclusiveFamilies = [], ?float $deadlineSeconds = null): bool
    {
        $result = $agents->run($node, 'firewall.inspect', [
            'expected' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), $rules),
            'exclusive_families' => $exclusiveFamilies,
            'stored' => false,
        ], $deadlineSeconds);
        if (! is_bool($result['matches'] ?? null)) {
            throw new DoctorInspectionException;
        }

        return $result['matches'];
    }
}
