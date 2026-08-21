<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\Convergence\UfwFirewallRulePlan;
use App\Data\Convergence\UfwFirewallRuleProbe;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Firewall\FirewallRuleShapeCanonicalizer;
use App\Services\Firewall\RemoteFirewallRule;

final readonly class UfwFirewallRule
{
    public function __construct(
        public string $name,
        public string $direction,
        public string $action,
        public string $source,
        public ?string $destination,
        public string $port,
        public string $protocol,
        public string $addressFamily,
        public ?string $interface,
        public ?string $reason,
    ) {}

    public static function fromRule(FirewallRule $rule): self
    {
        $rule->loadMissing('node');

        return new self(
            name: $rule->name,
            direction: $rule->direction,
            action: $rule->action,
            source: $rule->source,
            destination: $rule->destination,
            port: (string) $rule->port,
            protocol: $rule->protocol,
            addressFamily: $rule->address_family,
            interface: $rule->interface,
            reason: $rule->reason,
        );
    }

    public function probe(Node $node): UfwFirewallRuleProbe
    {
        [$snapshot, $error] = new FirewallRuleProbe()->tryIntrospectNode($node);

        if (! $snapshot instanceof ProbeSnapshot) {
            return new UfwFirewallRuleProbe(
                reachable: false,
                present: false,
                error: $error,
            );
        }

        foreach (FirewallRuleShapeCanonicalizer::concreteExpectedShapes($this->expectedShape()) as $expected) {
            if ($snapshot->get($this->identityKey($expected)) !== null) {
                continue;
            }

            return new UfwFirewallRuleProbe(
                reachable: true,
                present: false,
                partialMatch: $this->findPartialShapeMatch($snapshot, $expected),
            );
        }

        return new UfwFirewallRuleProbe(
            reachable: true,
            present: true,
        );
    }

    public function plan(UfwFirewallRuleProbe $probe): UfwFirewallRulePlan
    {
        if (! $probe->reachable) {
            return new UfwFirewallRulePlan(
                status: ConvergenceStatus::Unreachable,
                summary: "Could not inspect UFW rule {$this->name}.",
                details: $this->details(['error' => $probe->error]),
            );
        }

        if ($probe->present) {
            return new UfwFirewallRulePlan(
                status: ConvergenceStatus::Ok,
                summary: "UFW rule {$this->name} already matches gateway intent.",
                details: $this->details(),
            );
        }

        if ($probe->partialMatch !== null) {
            return new UfwFirewallRulePlan(
                status: ConvergenceStatus::Changed,
                summary: "Replace mismatched UFW rule {$this->name}.",
                details: $this->details([
                    'observed' => $probe->partialMatch,
                    'expected' => $this->expectedShape(),
                ]),
            );
        }

        return new UfwFirewallRulePlan(
            status: ConvergenceStatus::Changed,
            summary: "Apply missing UFW rule {$this->name}.",
            details: $this->details(['expected' => $this->expectedShape()]),
        );
    }

    public function apply(Node $node, UfwFirewallRulePlan $plan): ConvergenceApplyResult
    {
        if (! $plan->shouldApply()) {
            return new ConvergenceApplyResult(
                status: $plan->status,
                summary: $plan->summary,
                details: $plan->details,
            );
        }

        $observed = is_array($plan->details['observed'] ?? null) ? $plan->details['observed'] : null;
        $remoteFirewallRule = app(RemoteFirewallRule::class);

        if ($observed !== null) {
            $deleteResult = $remoteFirewallRule->delete($node, $this->stringKeyed($observed));

            if (! $deleteResult->successful()) {
                return new ConvergenceApplyResult(
                    status: ConvergenceStatus::Failed,
                    summary: "Failed to delete mismatched UFW rule {$this->name}.",
                    details: $this->details([
                        'exit_code' => $deleteResult->exitCode,
                        'error' => trim($deleteResult->stderr) !== '' ? trim($deleteResult->stderr) : null,
                    ]),
                );
            }
        }

        $applyResult = $remoteFirewallRule->apply($node, $this->mutationShape());

        if (! $applyResult->successful()) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply UFW rule {$this->name}.",
                details: $this->details([
                    'exit_code' => $applyResult->exitCode,
                    'error' => trim($applyResult->stderr) !== '' ? trim($applyResult->stderr) : null,
                ]),
            );
        }

        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Changed,
            summary: "Applied UFW rule {$this->name}.",
            details: $this->details(),
        );
    }

    public function applyCommand(): string
    {
        $parts = [
            'sudo ufw',
            ...$this->commandBody($this->mutationShape()),
        ];

        if (is_string($this->reason) && $this->reason !== '') {
            $parts[] = 'comment';
            $parts[] = escapeshellarg($this->reason);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $shape
     */
    public function deleteCommand(array $shape): string
    {
        $parts = [
            'sudo ufw delete',
            ...$this->commandBody($shape),
        ];

        return implode(' ', $parts);
    }

    public function reloadCommand(): string
    {
        return 'sudo ufw reload';
    }

    /**
     * @return array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family: string, interface: ?string}
     */
    public function expectedShape(): array
    {
        $source = $this->normalizeAnyEndpoint($this->source);
        $destination = $this->destination === null ? null : $this->normalizeAnyEndpoint($this->destination);

        return [
            'direction' => $this->direction,
            'action' => $this->action,
            'source' => $source,
            'destination' => $destination,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'address_family' => FirewallRuleShapeCanonicalizer::effectiveAddressFamily(
                $this->addressFamily,
                $source,
                $destination,
            ),
            'interface' => $this->interface,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mutationShape(): array
    {
        return [
            ...$this->expectedShape(),
            'reason' => $this->reason,
        ];
    }

    /**
     * @param  array{direction: string, action: string, source: string, destination: ?string, port: string, protocol: string, address_family?: string, interface?: ?string}  $expected
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
                && (($expected['address_family'] ?? 'both') === 'both'
                || ($observed['address_family'] ?? 'both') === ($expected['address_family'] ?? 'both'))
            ) {
                return $observed;
            }
        }

        return null;
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
     * @param  array<string, mixed>  $shape
     * @return list<string>
     */
    private function commandBody(array $shape): array
    {
        $addressFamily = is_string($shape['address_family'] ?? null) ? $shape['address_family'] : 'both';
        $source = is_string($shape['source'] ?? null) ? $shape['source'] : 'any';
        $destination = is_string($shape['destination'] ?? null) ? $shape['destination'] : 'any';
        $interface = is_string($shape['interface'] ?? null) && $shape['interface'] !== ''
            ? ['on', '$('.$this->interfaceResolver($shape['interface']).')']
            : [];

        return [
            (string) ($shape['action'] ?? 'allow'),
            ($shape['direction'] ?? 'incoming') === 'outgoing' ? 'out' : 'in',
            ...$interface,
            'from',
            escapeshellarg($this->endpointForFamily($source, $addressFamily)),
            'to',
            escapeshellarg($this->endpointForFamily($destination, $addressFamily)),
            'port',
            escapeshellarg((string) ($shape['port'] ?? '*')),
            'proto',
            escapeshellarg((string) ($shape['protocol'] ?? 'tcp')),
        ];
    }

    private function interfaceResolver(string $interface): string
    {
        if ($interface === 'wireguard') {
            return "ip -o link show type wireguard 2>/dev/null | awk -F': ' '{print \$2; exit}'";
        }

        return "ip route show default 0.0.0.0/0 2>/dev/null | awk '{print \$5; exit}'";
    }

    private function endpointForFamily(string $endpoint, string $addressFamily): string
    {
        if ($endpoint !== 'any') {
            return $endpoint;
        }

        if ($addressFamily === 'v4') {
            return '0.0.0.0/0';
        }

        if ($addressFamily === 'v6') {
            return '::/0';
        }

        return 'any';
    }

    private function normalizeAnyEndpoint(string $value): string
    {
        $value = match ($value) {
            '0.0.0.0/0', '::/0' => 'any',
            default => $value,
        };

        return FirewallRuleShapeCanonicalizer::canonicalizeHostCidr($value);
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

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function details(array $extra = []): array
    {
        return [
            'rule' => $this->name,
            'direction' => $this->direction,
            'action' => $this->action,
            'source' => $this->source,
            'destination' => $this->destination,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'address_family' => $this->addressFamily,
            'interface' => $this->interface,
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
    }
}
