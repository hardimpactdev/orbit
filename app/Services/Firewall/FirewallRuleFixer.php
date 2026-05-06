<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\FirewallRule;

final readonly class FirewallRuleFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
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

        if ($entry->key === 'firewall_rule.rule_mismatch') {
            $observed = is_array($entry->detail['observed'] ?? null) ? $entry->detail['observed'] : null;

            if ($observed !== null) {
                $this->remoteShell->run($rule->node, $this->deleteCommand($observed), ['throw' => false]);
            }
        }

        $this->remoteShell->run($rule->node, $this->applyCommand($rule), ['throw' => true]);
        $this->remoteShell->run($rule->node, 'sudo ufw reload', ['throw' => false]);

        return [
            'family' => 'firewall_rule',
            'node' => $rule->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Re-applied firewall rule {$rule->name} from gateway intent.",
            'details' => [
                'rule' => $rule->name,
            ],
        ];
    }

    private function applyCommand(FirewallRule $rule): string
    {
        $parts = [
            'sudo ufw',
            $rule->action,
            $rule->direction === 'outgoing' ? 'out' : 'in',
            'from',
            escapeshellarg($rule->source),
            'to',
            $rule->destination === null ? 'any' : escapeshellarg($rule->destination),
            'port',
            escapeshellarg((string) $rule->port),
            'proto',
            escapeshellarg($rule->protocol),
        ];

        if (is_string($rule->reason) && $rule->reason !== '') {
            $parts[] = 'comment';
            $parts[] = escapeshellarg($rule->reason);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    private function deleteCommand(array $shape): string
    {
        $parts = [
            'sudo ufw delete',
            (string) ($shape['action'] ?? 'allow'),
            ($shape['direction'] ?? 'incoming') === 'outgoing' ? 'out' : 'in',
            'from',
            escapeshellarg((string) ($shape['source'] ?? 'any')),
            'to',
            is_string($shape['destination'] ?? null) ? escapeshellarg((string) $shape['destination']) : 'any',
            'port',
            escapeshellarg((string) ($shape['port'] ?? '*')),
            'proto',
            escapeshellarg((string) ($shape['protocol'] ?? 'tcp')),
        ];

        return implode(' ', $parts);
    }
}
