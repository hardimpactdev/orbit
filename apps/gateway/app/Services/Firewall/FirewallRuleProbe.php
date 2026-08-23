<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Throwable;

final readonly class FirewallRuleProbe
{
    private const array Directions = ['incoming', 'outgoing'];

    private const array Actions = ['allow', 'deny'];

    private const array Protocols = ['tcp', 'udp'];

    public function __construct(
        private ?RemoteFirewallRuleProbe $remoteProbe = null,
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

        return $this->introspectNode($rule->node);
    }

    public function introspectNode(Node $node): ProbeSnapshot
    {
        $probe = $this->remoteProbe();
        $result = $probe->output($node);

        return $this->snapshotFromUfwOutput($probe->extractOutput($result));
    }

    /**
     * @return array{0: ProbeSnapshot|null, 1: string|null}
     */
    public function tryIntrospectNode(Node $node): array
    {
        $probe = $this->remoteProbe();

        try {
            $result = $probe->outputIfAvailable($node);
        } catch (Throwable $throwable) {
            return [null, $throwable->getMessage()];
        }

        if (! $result->successful()) {
            $error = $this->errorMessage($result);

            return [null, $error];
        }

        return [$this->snapshotFromUfwOutput($probe->extractOutput($result)), null];
    }

    public function snapshotFromUfwOutput(string $output): ProbeSnapshot
    {
        $items = [
            '__firewall_backend_inspected' => ['inspected' => true],
        ];

        foreach (explode("\n", $output) as $line) {
            $parsed = $this->parseUfwLine($line) ?? $this->parseUfwStoredRuleLine($line);

            if ($parsed === null) {
                continue;
            }

            $identity = $this->identityKey($parsed);
            $existing = $items[$identity] ?? null;

            if (
                is_array($existing)
                && ($existing['comment'] ?? '') !== ''
                && ($parsed['comment'] ?? '') === ''
            ) {
                continue;
            }

            $items[$identity] = $parsed;
        }

        return new ProbeSnapshot($items);
    }

    private function remoteProbe(): RemoteFirewallRuleProbe
    {
        return $this->remoteProbe ?? app(RemoteFirewallRuleProbe::class);
    }

    private function errorMessage(RemoteShellResult $result): string
    {
        if (trim($result->stderr) !== '') {
            return trim($result->stderr);
        }

        if (trim($result->stdout) !== '') {
            return trim($result->stdout);
        }

        return "UFW introspection exited with code {$result->exitCode}.";
    }

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node, ProbeSnapshot $snapshot): array
    {
        $results = [];

        foreach ($snapshot->items as $key => $observed) {
            if (str_starts_with($key, '__firewall_backend_')) {
                continue;
            }

            if ($this->isBaselineRule($observed)) {
                continue;
            }

            $existing = FirewallRule::query()
                ->where('node_id', $node->id)
                ->where('direction', $observed['direction'])
                ->where('action', $observed['action'])
                ->where('source', $observed['source'])
                ->where('destination', $observed['destination'] ?? null)
                ->where('port', $observed['port'])
                ->where('protocol', $observed['protocol'])
                ->first();

            if ($existing instanceof FirewallRule) {
                continue;
            }

            $name = $this->deriveName($observed);

            $collision = FirewallRule::query()
                ->where('node_id', $node->id)
                ->where('name', $name)
                ->first();

            if ($collision instanceof FirewallRule) {
                $results[] = new AdoptResult(
                    family: $this->key(),
                    key: $key,
                    action: AdoptAction::Conflict,
                    summary: "Name collision: '{$name}' already exists with different identity.",
                );

                continue;
            }

            $shape = [
                'direction' => $observed['direction'],
                'action' => $observed['action'],
                'source' => $observed['source'],
                'destination' => $observed['destination'] ?? null,
                'port' => $observed['port'],
                'protocol' => $observed['protocol'],
            ];

            FirewallRule::query()->create([
                'node_id' => $node->id,
                'name' => $name,
                ...$shape,
                'reason' => $observed['comment'] ?? null,
                'source_hash' => hash('sha256', json_encode([
                    'node' => $node->name,
                    'name' => $name,
                    'shape' => $shape,
                    'reason' => $observed['comment'] ?? null,
                ], JSON_THROW_ON_ERROR)),
                'address_family' => $observed['address_family'] ?? 'both',
                'interface' => $observed['interface'] ?? null,
                'owner' => 'user',
            ]);

            $results[] = new AdoptResult(
                family: $this->key(),
                key: $key,
                action: AdoptAction::Created,
                summary: "Adopted firewall rule '{$name}' ({$key}).",
            );
        }

        return $results;
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
            || ! in_array($rule->address_family, ['v4', 'v6', 'both'], true)
            || $rule->interface !== null
            && ! in_array($rule->interface, ['public', 'wireguard'], true)
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

        if (
            ! $rule->node->isActive()
            || ! $this->isUbuntuPlatform($rule->node)
            || ! $this->canOwnFirewallRules($rule->node)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'firewall_rule.node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Firewall rule {$rule->name} targets node {$rule->node->name}, which is not an active Ubuntu node eligible to own firewall rules.",
                    detail: [
                        'node' => $rule->node->name,
                        'role' => $rule->node->displayRole(),
                        'status' => $rule->node->status,
                        'platform' => $rule->node->platform,
                    ],
                ),
            ];
        }

        return [];
    }

    private function isUbuntuPlatform(Node $node): bool
    {
        return $node->platform === 'ubuntu' || str_starts_with((string) $node->platform, 'ubuntu_');
    }

    private function canOwnFirewallRules(Node $node): bool
    {
        return app(NodeRoleAssignments::class)->nodeCanOwnFirewallRules($node);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkBaselinePolicyBoundary(FirewallRule $rule): array
    {
        if (
            $rule->direction === 'incoming'
            && $rule->action === 'allow'
            && $rule->source === 'any'
            && $rule->destination === null
            && $rule->protocol === 'tcp'
            && $rule->port === '22'
        ) {
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

        foreach (FirewallRuleShapeCanonicalizer::concreteExpectedShapes($this->expectedShape($rule)) as $expected) {
            if ($snapshot->get($this->identityKey($expected)) !== null) {
                continue;
            }

            $partial = $this->findPartialShapeMatch($snapshot, $expected, $rule->reason, $rule->name);

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

        return [];
    }

    /**
     * @return array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family: string, interface: ?string}
     */
    private function expectedShape(FirewallRule $rule): array
    {
        $source = $this->normalizeEndpoint($this->normalizeAnyEndpoint($rule->source));
        $destination = $rule->destination === null
            ? null
            : $this->normalizeEndpoint($this->normalizeAnyEndpoint($rule->destination));

        return [
            'direction' => $rule->direction,
            'action' => $rule->action,
            'source' => $source,
            'destination' => $destination,
            'port' => $rule->port,
            'protocol' => $rule->protocol,
            'address_family' => FirewallRuleShapeCanonicalizer::effectiveAddressFamily(
                $rule->address_family,
                $source,
                $destination,
            ),
            'interface' => $rule->interface,
        ];
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family?: string, interface?: ?string}  $expected
     * @return array<string, mixed>|null
     */
    private function findPartialShapeMatch(
        ProbeSnapshot $snapshot,
        array $expected,
        ?string $reason,
        string $name,
    ): ?array {
        foreach ($snapshot->items as $observed) {
            if (($observed['inspected'] ?? false) === true) {
                continue;
            }

            if (! FirewallRuleShapeCanonicalizer::managedCommentIdentifiesObservedRule($reason, $name, $observed)) {
                continue;
            }

            if (
                ($observed['direction'] ?? null) === $expected['direction']
                && ($observed['action'] ?? null) === $expected['action']
                && ($observed['port'] ?? null) === $expected['port']
                && ($observed['protocol'] ?? null) === $expected['protocol']
                && (($expected['address_family'] ?? 'both') === 'both'
                || ($observed['address_family'] ?? 'both') === ($expected['address_family'] ?? 'both'))
            ) {
                return $observed;
            }
        }

        return null;
    }

    /**
     * @return array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family: string, interface: ?string, comment: string}|null
     */
    private function parseUfwLine(string $line): ?array
    {
        $line = trim($line);

        if (
            $line === ''
            || ! preg_match(
                '/^\[\s*\d+\]\s+(.+?)\s{2,}(ALLOW|DENY)\s+(IN|OUT)\s{2,}(.+?)(?:\s{2,}#\s*(.*))?$/',
                $line,
                $matches,
            )
        ) {
            return null;
        }

        $target = trim($matches[1]);
        $addressFamily = str_contains($target, '(v6)') || str_contains($matches[4], '(v6)') ? 'v6' : 'v4';
        $target = trim(str_replace('(v6)', '', $target));
        $source = $this->normalizeEndpoint(trim(str_replace('(v6)', '', $matches[4])));
        $port = '*';
        $protocol = '*';
        $interface = null;

        if (preg_match('/^(.+?)\s+on\s+([a-zA-Z0-9_.:-]+)$/', $target, $interfaceMatches)) {
            $target = trim($interfaceMatches[1]);
            $interface = $this->normalizeInterface($interfaceMatches[2]);
        }

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
            'address_family' => $addressFamily,
            'interface' => $interface,
            'comment' => $matches[5] ?? '',
        ];
    }

    /**
     * @return array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family: string, interface: ?string, comment: string}|null
     */
    private function parseUfwStoredRuleLine(string $line): ?array
    {
        $line = trim($line);

        if (! preg_match('/^__orbit_ufw_file:(user6|user):\s*-A\s+ufw6?-user-input\s+(.+)$/', $line, $matches)) {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($matches[2])) ?: [];
        $jump = $this->tokenAfter($tokens, '-j');
        $action = match ($jump) {
            'ACCEPT' => 'allow',
            'DROP', 'REJECT' => 'deny',
            default => null,
        };
        $port = $this->tokenAfter($tokens, '--dport');
        $protocol = $this->tokenAfter($tokens, '-p');

        if ($action === null || $port === null || $protocol === null) {
            return null;
        }

        return [
            'direction' => 'incoming',
            'action' => $action,
            'source' => $this->normalizeAnyEndpoint($this->tokenAfter($tokens, '-s') ?? 'any'),
            'destination' => null,
            'port' => $port,
            'protocol' => $protocol,
            'address_family' => $matches[1] === 'user6' ? 'v6' : 'v4',
            'interface' => ($interface = $this->tokenAfter($tokens, '-i')) === null
                ? null
                : $this->normalizeInterface($interface),
            'comment' => '',
        ];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function tokenAfter(array $tokens, string $token): ?string
    {
        $index = array_search($token, $tokens, true);

        if (! is_int($index)) {
            return null;
        }

        $value = $tokens[$index + 1] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function normalizeEndpoint(string $value): string
    {
        $value = match ($value) {
            'Anywhere' => 'any',
            default => $value,
        };

        return FirewallRuleShapeCanonicalizer::canonicalizeHostCidr($value);
    }

    private function normalizeAnyEndpoint(string $value): string
    {
        $value = match ($value) {
            '0.0.0.0/0', '::/0' => 'any',
            default => $value,
        };

        return FirewallRuleShapeCanonicalizer::canonicalizeHostCidr($value);
    }

    private function normalizeInterface(string $interface): string
    {
        if ($interface === 'wg0' || str_starts_with($interface, 'wg-')) {
            return 'wireguard';
        }

        return 'public';
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family?: string, interface?: ?string}  $shape
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
            $shape['address_family'] ?? 'both',
            $shape['interface'] ?? 'any',
        ]);
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family?: string, interface?: ?string}  $observed
     */
    private function isBaselineRule(array $observed): bool
    {
        return (
            $observed['direction'] === 'incoming'
            && $observed['action'] === 'allow'
            && $observed['source'] === 'any'
            && $observed['destination'] === null
            && $observed['protocol'] === 'tcp'
            && $observed['port'] === '22'
        );
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, comment?: string, address_family?: string, interface?: ?string}  $observed
     */
    private function deriveName(array $observed): string
    {
        $comment = $observed['comment'] ?? '';

        if (str_starts_with($comment, 'orbit:')) {
            $name = substr($comment, 6);

            if ($name !== '') {
                return $name;
            }
        }

        if ($comment !== '') {
            $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '-', $comment);

            if ($sanitized !== '' && $sanitized !== '-') {
                return strtolower(trim((string) $sanitized, '-'));
            }
        }

        $direction = $observed['direction'];
        $action = $observed['action'];
        $port = $observed['port'];
        $protocol = $observed['protocol'];

        if ($port !== '*' && $protocol !== '*') {
            return "{$direction}-{$action}-{$port}-{$protocol}";
        }

        if ($port !== '*') {
            return "{$direction}-{$action}-{$port}";
        }

        return "{$direction}-{$action}-rule";
    }
}
