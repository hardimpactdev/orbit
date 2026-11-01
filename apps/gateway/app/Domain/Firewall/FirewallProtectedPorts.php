<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Models\Node;

/** A deny may never cut a Node off: SSH, recovery SSH, WireGuard and every managed role port stay open. */
final readonly class FirewallProtectedPorts
{
    public function __construct(private NodeFirewallDesiredRules $managed, private VpnSettings $vpn, private NodeFirewallRuleCatalog $catalog) {}

    public function guard(Node $node, string $port): void
    {
        $ports = ['22', (string) $node->public_ssh_port, $this->vpn->port()];
        foreach ($this->managed->forNode($node) as $rule) {
            $ports[] = $rule->shape->port;
        }
        foreach ($node->roles()->get() as $assignment) {
            foreach ($this->catalog->ownedByRole($node, $assignment->role) as $rule) {
                $ports[] = $rule->shape->port;
            }
        }
        foreach (array_unique($ports) as $protected) {
            if ($protected !== 'any' && self::overlaps($protected, $port)) {
                throw new ResourceOperationException('firewall.protected_port', "A deny on port {$port} would block Orbit's port {$protected} on this Node.");
            }
        }
    }

    public static function overlaps(string $left, string $right): bool
    {
        $a = array_map(intval(...), explode(':', $left));
        $b = array_map(intval(...), explode(':', $right));

        return $a[0] <= ($b[1] ?? $b[0]) && $b[0] <= ($a[1] ?? $a[0]);
    }
}
