<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;

/** A scope selects existing assignments; it never creates, removes or activates one, and never holds a record-only role. */
final readonly class NodeConvergeScope
{
    /** @param list<string>|null $roles
     * @return list<string>
     */
    public static function roles(Node $node, bool $base, ?array $roles): array
    {
        $recordOnly = app(RoleRegistry::class)->recordOnly();
        if ($roles !== null && ($held = array_values(array_intersect($roles, $recordOnly))) !== []) {
            throw new ResourceOperationException('validation.failed', 'Role ['.implode(', ', $held).'] is only recorded by the Gateway and has no host work to converge.', 422);
        }
        $assigned = $node->roles()->whereNotIn('role', $recordOnly)->orderBy('id')->get()
            ->map(static fn (NodeRole $assignment): string => $assignment->role->value)->all();
        if (($base && $roles !== null) || ($roles !== null && ($roles === [] || array_diff($roles, $assigned) !== []))) {
            throw new ResourceOperationException('validation.failed', 'Select either the base or a nonempty list of roles already assigned to the Node.', 422);
        }

        return $base ? [] : array_values(array_unique($roles ?? $assigned));
    }
}
