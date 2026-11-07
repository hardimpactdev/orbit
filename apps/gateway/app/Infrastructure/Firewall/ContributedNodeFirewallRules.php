<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Models\Node;

/**
 * A plug-in point: the UFW rules an extension keeps on a Node ([Extensions](/reference/extensions#plug-in-points)).
 *
 * The extension's own operation applies them. The desired firewall of the Node lists them, so `firewall:list --managed` shows them
 * and the live check matches them. A contribution returns no rules for a Node it does not run on, or while its extension is off.
 */
interface ContributedNodeFirewallRules
{
    /** @return list<UfwManagedRule> */
    public function forNode(Node $node): array;
}
