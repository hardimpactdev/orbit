<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Firewall\DesiredFirewallRule;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Models\Node;

/** A deny may never cut a Node off: SSH, recovery SSH, WireGuard and every managed role port stay open. */
final readonly class FirewallProtectedPorts
{
    public function __construct(private NodeFirewallDesiredRules $managed, private VpnSettings $vpn, private NodeFirewallRuleCatalog $catalog) {}

    public function guard(Node $node, string $port): void
    {
        foreach (array_unique(array_column($this->entries($node), 'port')) as $protected) {
            if (self::overlaps($protected, $port)) {
                throw new ResourceOperationException('firewall.protected_port', "A deny on port {$port} would block Orbit's port {$protected} on this Node.");
            }
        }
    }

    /** An allow repeats Orbit's own access when its ports lie inside one protected entry that is open to its source. */
    public function repeats(Node $node, string $port, string $source): bool
    {
        return array_any($this->entries($node), fn ($entry) => FirewallSource::contains($entry['source'], $source) && self::within($port, $entry['port']));
    }

    /** @return list<array{port: string, source: string}> */
    private function entries(Node $node): array
    {
        $entries = array_map(static fn (string $port): array => ['port' => $port, 'source' => 'any'],
            ['22', (string) $node->public_ssh_port, $this->vpn->port()]);
        $shapes = array_map(static fn (DesiredFirewallRule $rule): UfwRuleShape => $rule->shape, $this->managed->forNode($node));
        foreach ($node->roles()->get() as $assignment) {
            foreach ($this->catalog->ownedByRole($node, $assignment->role) as $rule) {
                $shapes[] = $rule->shape;
            }
        }
        foreach ($shapes as $shape) {
            $entries[] = ['port' => $shape->port, 'source' => $shape->action === 'allow' ? $shape->source : ''];
        }

        return array_values(array_filter($entries, static fn (array $entry): bool => $entry['port'] !== 'any'));
    }

    public static function within(string $inner, string $outer): bool
    {
        $a = array_map(intval(...), explode(':', $inner));
        $b = array_map(intval(...), explode(':', $outer));

        return $b[0] <= $a[0] && ($a[1] ?? $a[0]) <= ($b[1] ?? $b[0]);
    }

    public static function overlaps(string $left, string $right): bool
    {
        $a = array_map(intval(...), explode(':', $left));
        $b = array_map(intval(...), explode(':', $right));

        return $a[0] <= ($b[1] ?? $b[0]) && $b[0] <= ($a[1] ?? $a[0]);
    }
}
