<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Contracts\RemoteShell;
use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\Convergence\UfwFirewallRulePlan;
use App\Data\Doctor\DriftEntry;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\FirewallRule;
use App\Services\Convergence\UfwFirewallRule;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class FirewallRuleFixer
{
    public function __construct(
        private RemoteShell $_remoteShell,
        private RemoteFirewallRule $remoteFirewallRule,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(FirewallRule $rule, DriftEntry $entry): ?array
    {
        if (! in_array($entry->key, ['firewall_rule.rule_missing', 'firewall_rule.rule_mismatch'], true)) {
            return null;
        }

        $rule->loadMissing('node');

        return $this->convergeRule($rule, $entry);
    }

    public function remove(FirewallRule $rule): void
    {
        $rule->loadMissing('node');

        $convergence = UfwFirewallRule::fromRule($rule);

        $result = $this->remoteFirewallRule->delete($rule->node, $convergence->mutationShape());

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->stderr) !== '' ? trim($result->stderr) : $result->stdout);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function convergeRule(FirewallRule $rule, DriftEntry $entry): array
    {
        $convergence = UfwFirewallRule::fromRule($rule);
        $probe = $convergence->probe($rule->node);
        $plan = $convergence->plan($probe);
        $result = $this->apply($rule, $convergence, $plan);

        if ($result->status === ConvergenceStatus::Failed) {
            throw new RuntimeException((string) ($result->details['error'] ?? $result->summary));
        }

        if ($result->status === ConvergenceStatus::Unreachable) {
            throw new RuntimeException((string) ($result->details['error'] ?? $result->summary));
        }

        return [
            'family' => 'firewall_rule',
            'node' => $rule->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => $result->changed() ? 'completed' : 'completed',
            'summary' => $result->summary,
            'details' => [
                'rule' => $rule->name,
                'convergence_status' => $result->status->value,
            ],
        ];
    }

    private function apply(
        FirewallRule $rule,
        UfwFirewallRule $convergence,
        UfwFirewallRulePlan $plan,
    ): ConvergenceApplyResult {
        if (! $plan->shouldApply()) {
            return new ConvergenceApplyResult(
                status: $plan->status,
                summary: $plan->summary,
                details: $plan->details,
            );
        }

        $observed = is_array($plan->details['observed'] ?? null)
            ? $this->stringKeyed($plan->details['observed'])
            : null;

        if ($observed !== null) {
            $deleteResult = $this->remoteFirewallRule->delete($rule->node, $observed);

            if (! $deleteResult->successful()) {
                return new ConvergenceApplyResult(
                    status: ConvergenceStatus::Failed,
                    summary: "Failed to delete mismatched UFW rule {$convergence->name}.",
                    details: [
                        ...$plan->details,
                        'exit_code' => $deleteResult->exitCode,
                        'error' => trim($deleteResult->stderr) !== '' ? trim($deleteResult->stderr) : null,
                    ],
                );
            }
        }

        $applyResult = $this->remoteFirewallRule->apply($rule->node, $convergence->mutationShape());

        if (! $applyResult->successful()) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply UFW rule {$convergence->name}.",
                details: [
                    ...$plan->details,
                    'exit_code' => $applyResult->exitCode,
                    'error' => trim($applyResult->stderr) !== '' ? trim($applyResult->stderr) : null,
                ],
            );
        }

        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Changed,
            summary: "Applied UFW rule {$convergence->name}.",
            details: $plan->details,
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
