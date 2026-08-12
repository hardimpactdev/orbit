<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleProbe;
use Illuminate\Support\Collection;

final readonly class DoctorFirewallRuleFamilyProbe
{
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private FirewallRuleProbe $firewallRuleProbe,
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(
        Node $node,
        ?string $key,
        ?callable $onFamilyProgress,
    ): array {
        $firewallRules = $this->firewallRulesForNode($node);

        return $this->familyProbeRunner->run(
            node: $node,
            family: 'firewall_rule',
            total: $firewallRules->count(),
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use ($firewallRules): void {
                foreach ($firewallRules as $rule) {
                    $snapshot = $this->firewallRuleProbe->introspect($rule);

                    foreach ($this->firewallRuleProbe->diff($rule, $snapshot) as $entry) {
                        $addIssue($this->issue($entry, $rule));
                    }

                    $advance();
                }
            },
        );
    }

    /**
     * @return Collection<int, FirewallRule>
     */
    private function firewallRulesForNode(Node $node): Collection
    {
        /** @mago-expect lint:inline-variable-return */
        $rules = FirewallRule::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->orderBy('id')
            ->get()
            ->values();

        /** @var Collection<int, FirewallRule> $rules */
        return $rules;
    }

    private function issue(DriftEntry $entry, FirewallRule $rule): DoctorIssue
    {
        $rule->loadMissing('node');

        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $rule->node->name,
            detail: [
                ...($entry->detail ?? []),
                'rule' => $rule->name,
            ],
        );
    }
}
