<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Models\FirewallRule;
use App\Models\Node;

final readonly class FirewallInspectionTarget
{
    public function __construct(
        public Node $node,
        public FirewallInspectionShape $shape,
        public int|string $resourceId,
        public string $resourceName,
    ) {}

    /** @return list<self> One target per ufw protocol the rule covers. */
    public static function forRule(FirewallRule $rule): array
    {
        $rule->loadMissing('node');
        $family = FirewallSource::family($rule->source);

        return array_map(static fn (string $protocol): self => new self(
            node: $rule->node,
            shape: new FirewallInspectionShape(
                comment: $rule->comment(), action: $rule->action->value, direction: 'in', source: $rule->source,
                destination: 'any', port: $rule->port, protocol: $protocol, inInterface: null, outInterface: null,
                family: $family === 'both' ? null : $family,
            ),
            resourceId: $rule->id,
            resourceName: $rule->identity(),
        ), $rule->protocols());
    }
}
