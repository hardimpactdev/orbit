<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Data\Firewall\FirewallRuleData;
use App\Domain\Firewall\FirewallRuleOrder;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Support\ValidatedData;

/**
 * The firewall rules of the given Nodes, from the Gateway's records and the agents' latest `ufw` facts. It never
 * reaches a Node. Per Node: the operator rules in the order UFW checks them; with `managed`, Orbit's own `orbit:`
 * rules; with `live`, how the live rules compare with them.
 */
final readonly class ListFirewallRulesAction
{
    /** Worst first: a rule is as sound as its weakest line. */
    private const array Severity = ['missing' => 0, 'drift' => 1, 'unverifiable' => 2, 'exact' => 3];

    public function __construct(
        private ListManagedFirewallRulesAction $managedRules,
        private ListLiveFirewallRulesAction $liveRules,
        private NodeAgentLiveness $liveness,
    ) {}

    /**
     * @param  list<int>  $nodeIds
     * @return list<array<string, mixed>>
     */
    public function execute(array $nodeIds, bool $managed, bool $live): array
    {
        return array_values(Node::query()
            ->with('firewallRules')
            ->whereIn('id', $nodeIds)
            ->orderBy('id')
            ->get()
            ->map(fn (Node $node): array => $this->forNode($node, $managed, $live))
            ->all());
    }

    /** @return array<string, mixed> */
    private function forNode(Node $node, bool $managed, bool $live): array
    {
        $ordered = FirewallRuleOrder::sort($node->firewallRules);
        $rules = array_map(static function (FirewallRule $rule): array {
            $data = FirewallRuleData::fromModel($rule)->toArray();
            unset($data['backend_status']);

            return $data;
        }, $ordered);
        $managedRows = $managed ? $this->managedRules->execute($node) : [];
        $entry = ['node_id' => $node->id, 'node' => $node->name];

        if (! $live) {
            return [...$entry, 'rules' => $rules, ...($managed ? ['managed' => $managedRows] : [])];
        }

        $agent = $this->liveness->for($node);
        $read = $this->liveRules->execute($node);
        $matches = $this->matches($read);
        // A firewall that is off or not installed applies none of the rules; with no report, nothing can be said.
        $absent = in_array($read['backend_status'], ['inactive', 'absent'], true) ? 'missing' : 'unverifiable';
        $ruleMatches = $read['backend_status'] === 'active' ? $this->liveRules->matchRules($node, $ordered) : [];
        $mark = static fn (array $row, string $name): array => [...$row, 'match' => $matches[$name] ?? $absent];
        $unmatched = array_values(array_filter(
            $read['live'],
            static fn (array $row): bool => in_array($row['match'], ['unmanaged', 'drift'], true)
                && ($managed || ! str_starts_with(ValidatedData::string($row['comment']), 'orbit:')),
        ));

        return [
            ...$entry,
            'agent' => $agent?->toArray(),
            'rules' => array_map(static fn (array $row): array => [...$row, 'match' => $ruleMatches[$row['id']] ?? $absent], $rules),
            ...($managed ? ['managed' => array_map(static fn (array $row): array => $mark($row, $row['name']), $managedRows)] : []),
            'live' => [
                'backend_status' => $read['backend_status'],
                'observed_at' => $read['observed_at'],
                'possibly_outdated' => $agent?->state === NodeAgentState::Offline,
                'unmatched' => $unmatched,
            ],
        ];
    }

    /**
     * The match of each intended rule by name, the weakest line deciding.
     *
     * @param  array{live: list<array<string, mixed>>, missing: list<array<string, mixed>>}  $read
     * @return array<string, string>
     */
    private function matches(array $read): array
    {
        $matches = [];

        foreach ([...$read['live'], ...$read['missing']] as $row) {
            $name = ValidatedData::string($row['name']);
            $match = ValidatedData::string($row['match']);

            if (isset(self::Severity[$match]) && self::Severity[$match] < self::Severity[$matches[$name] ?? 'exact']) {
                $matches[$name] = $match;
            } elseif (isset(self::Severity[$match])) {
                $matches[$name] ??= $match;
            }
        }

        return $matches;
    }
}
