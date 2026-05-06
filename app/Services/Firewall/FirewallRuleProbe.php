<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Contracts\RemoteShell;
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

    public function __construct(
        private ?RemoteShell $remoteShell = null,
    ) {}

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
        $rule->loadMissing('node');

        if (! $rule->node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($rule->node, 'sudo ufw status numbered', ['throw' => true]);
        $items = [
            '__firewall_backend_inspected' => ['inspected' => true],
        ];

        foreach (explode("\n", $result->stdout) as $line) {
            $parsed = $this->parseUfwLine($line);

            if ($parsed === null) {
                continue;
            }

            $items[$this->identityKey($parsed)] = $parsed;
        }

        return new ProbeSnapshot($items);
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
            ...$this->checkBackendReality($rule, $snapshot),
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

    /**
     * @return list<DriftEntry>
     */
    private function checkBackendReality(FirewallRule $rule, ProbeSnapshot $snapshot): array
    {
        if ($snapshot->get('__firewall_backend_inspected') === null) {
            return [];
        }

        $expected = $this->expectedShape($rule);
        $observed = $snapshot->get($this->identityKey($expected));

        if ($observed !== null) {
            return [];
        }

        $partial = $this->findPartialShapeMatch($snapshot, $expected);

        if ($partial !== null) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'firewall_rule.rule_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Firewall backend rule {$rule->name} differs from gateway firewall intent.",
                    detail: [
                        'expected' => $expected,
                        'observed' => $partial,
                    ],
                ),
            ];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'firewall_rule.rule_missing',
                kind: DriftKind::Missing,
                summary: "Firewall backend rule {$rule->name} is missing on the target node.",
                detail: [
                    'expected' => $expected,
                ],
            ),
        ];
    }

    /**
     * @return array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string}
     */
    private function expectedShape(FirewallRule $rule): array
    {
        return [
            'direction' => $rule->direction,
            'action' => $rule->action,
            'source' => $rule->source,
            'destination' => $rule->destination,
            'port' => $rule->port,
            'protocol' => $rule->protocol,
        ];
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string}  $expected
     * @return array<string, mixed>|null
     */
    private function findPartialShapeMatch(ProbeSnapshot $snapshot, array $expected): ?array
    {
        foreach ($snapshot->items as $observed) {
            if (($observed['inspected'] ?? false) === true) {
                continue;
            }

            if (
                ($observed['direction'] ?? null) === $expected['direction']
                && ($observed['action'] ?? null) === $expected['action']
                && ($observed['port'] ?? null) === $expected['port']
                && ($observed['protocol'] ?? null) === $expected['protocol']
            ) {
                return $observed;
            }
        }

        return null;
    }

    /**
     * @return array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string}|null
     */
    private function parseUfwLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '' || ! preg_match('/^\[\s*\d+\]\s+(.+?)\s{2,}(ALLOW|DENY)\s+(IN|OUT)\s{2,}(.+?)(?:\s{2,}#\s*(.*))?$/', $line, $matches)) {
            return null;
        }

        if (str_contains($matches[1], '(v6)') || str_contains($matches[4], '(v6)')) {
            return null;
        }

        $target = trim($matches[1]);
        $source = $this->normalizeEndpoint(trim($matches[4]));
        $port = '*';
        $protocol = '*';

        if (preg_match('/^(\d{1,5}(?::\d{1,5})?)(?:\/(tcp|udp))?$/', $target, $targetMatches)) {
            $port = $targetMatches[1];
            $protocol = $targetMatches[2] ?? '*';
        }

        return [
            'direction' => $matches[3] === 'OUT' ? 'outgoing' : 'incoming',
            'action' => mb_strtolower($matches[2]),
            'source' => $source,
            'destination' => null,
            'port' => $port,
            'protocol' => $protocol,
        ];
    }

    private function normalizeEndpoint(string $value): string
    {
        return match ($value) {
            'Anywhere' => 'any',
            default => $value,
        };
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string}  $shape
     */
    private function identityKey(array $shape): string
    {
        return implode(':', [
            $shape['direction'],
            $shape['action'],
            $shape['source'],
            $shape['destination'] ?? 'any',
            $shape['port'],
            $shape['protocol'],
        ]);
    }
}
