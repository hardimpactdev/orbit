<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;

final class TaskVmCapabilities
{
    public static function requireOwner(string $kind): void
    {
        $definition = app(OperationKinds::class)->definition($kind);
        if (! is_array($definition)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'The owning family has not enabled this Swamp effect.', 409);
        }
    }

    /** @param list<string> $owners */
    public static function requireComposition(string $kind, array $owners): void
    {
        $definition = app(OperationKinds::class)->definition($kind);
        $children = is_array($definition) ? array_column($definition['children'] ?? [], 'kind') : [];
        foreach ($owners as $owner) {
            self::requireOwner($owner);
            $ownerDefinition = app(OperationKinds::class)->definition($owner);
            if (! in_array($owner, $children, true) || ! is_array($ownerDefinition) || ! isset($ownerDefinition['admission'])) {
                throw new ResourceOperationException('operation.kind_unavailable', 'The task VM parent has no owning child composition yet.', 409);
            }
        }
    }
}
