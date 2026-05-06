<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;

final readonly class FirewallRuleProbe
{
    private const array Directions = ['incoming', 'outgoing'];

    private const array Actions = ['allow', 'deny'];

    private const array Protocols = ['tcp', 'udp'];

    public function key(): string
    {
        return 'firewall_rule';
    }

    public function label(): string
    {
        return 'Firewall rules';
    }

    public function introspect(FirewallRule $rule): ProbeSnapshot
    {
        return new ProbeSnapshot([]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(FirewallRule $rule, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkRecordCompleteness($rule),
            ...$this->checkNodeEligibility($rule),
            ...$this->checkBaselinePolicyBoundary($rule),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(FirewallRule $rule): array
    {
        if (
            $rule->name === ''
            || ! is_int($rule->node_id)
            || ! in_array($rule->direction, self::Directions, true)
            || ! in_array($rule->action, self::Actions, true)
            || $rule->source === ''
            || $rule->port === ''
            || ! in_array($rule->protocol, self::Protocols, true)
            || $rule->source_hash === ''
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'firewall_rule.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Firewall rule record {$rule->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkNodeEligibility(FirewallRule $rule): array
    {
        $rule->loadMissing('node');

        if (! $rule->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'firewall_rule.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Firewall rule {$rule->name} points at a missing node.",
                ),
            ];
        }

        if ($rule->node->status !== 'active' || $rule->node->platform !== 'ubuntu' || ! in_array($rule->node->role, ['gateway', 'app'], true)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'firewall_rule.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Firewall rule {$rule->name} targets node {$rule->node->name}, which is not an active Ubuntu gateway or app node.",
                    detail: [
                        'node' => $rule->node->name,
                        'role' => $rule->node->role,
                        'status' => $rule->node->status,
                        'platform' => $rule->node->platform,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBaselinePolicyBoundary(FirewallRule $rule): array
    {
        if ($rule->direction === 'incoming' && $rule->action === 'allow' && $rule->source === 'any' && $rule->destination === null && $rule->protocol === 'tcp' && $rule->port === '22') {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'firewall_rule.baseline_conflict',
                    kind: DriftKind::Divergent,
                    summary: "Firewall rule {$rule->name} attempts to manage node bootstrap SSH policy.",
                    detail: [
                        'port' => $rule->port,
                        'protocol' => $rule->protocol,
                    ],
                ),
            ];
        }

        return [];
    }
}
