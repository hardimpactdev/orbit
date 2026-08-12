<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleFixer;
use Throwable;

final readonly class DoctorFirewallRuleRestorer
{
    public function __construct(
        private FirewallRuleFixer $firewallRuleFixer,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function apply(Node $node, string $key, array $detail): ?array
    {
        $ruleName = is_string($detail['rule'] ?? null) ? $detail['rule'] : null;

        if ($ruleName === null) {
            return null;
        }

        $rule = FirewallRule::query()
            ->where('node_id', $node->id)
            ->where('name', $ruleName)
            ->first();

        if (! $rule instanceof FirewallRule) {
            return null;
        }

        return $this->restoreRule($rule, new DriftEntry(
            family: 'firewall_rule',
            key: $key,
            kind: DriftKind::Divergent,
            summary: '',
            detail: $detail,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreRule(FirewallRule $rule, DriftEntry $entry): ?array
    {
        try {
            return $this->firewallRuleFixer->fix($rule, $entry);
        } catch (Throwable $exception) {
            $rule->loadMissing('node');

            return [
                'family' => $entry->family,
                'node' => $rule->node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }
}
