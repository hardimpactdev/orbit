<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;

/** A scope selects existing assignments; it never creates, removes or activates one. */
final readonly class NodeConvergeScope
{
    /** @param list<string>|null $roles
     * @return list<string>
     */
    public static function roles(Node $node, bool $base, ?array $roles): array
    {
        $assigned = $node->roles()->orderBy('id')->get()->map(static fn (NodeRole $assignment): string => $assignment->role->value)->all();
        if (($base && $roles !== null) || ($roles !== null && ($roles === [] || array_diff($roles, $assigned) !== []))) {
            throw new ResourceOperationException('validation.failed', 'Select either the base or a nonempty list of roles already assigned to the Node.', 422);
        }

        return $base ? [] : array_values(array_unique($roles ?? $assigned));
    }
}
