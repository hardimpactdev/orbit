<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use App\Data\Nodes\NodeAccessPermissions;
use InvalidArgumentException;

/** @mago-expect lint:kan-defect */
final readonly class NodePermissionNormalizer
{
    public function __construct(
        private NodePermissionRegistry $registry,
    ) {}

    /**
     * Normalize a permission set.
     *
     * Removes duplicate permissions, removes permissions that are implied by
     * another permission in the set, and validates that all permissions are
     * registry-known.
     *
     * @param  list<string>  $permissions
     *
     * @throws InvalidArgumentException when an unknown permission is present.
     */
    public function normalize(array $permissions): NodeAccessPermissions
    {
        $this->validate($permissions);

        $unique = array_values(array_unique($permissions));

        // Sort to ensure deterministic output.
        sort($unique);

        $kept = [];
        $removed = [];

        foreach ($unique as $permission) {
            $covered = false;

            foreach ($kept as $keptPermission) {
                if ($this->registry->isCoveredBy($permission, $keptPermission)) {
                    $covered = true;
                    $removed[] = $permission;

                    break;
                }
            }

            if (! $covered) {
                // Check if this permission covers any already-kept permissions.
                // If so, remove the covered ones.
                $newKept = [];

                foreach ($kept as $keptPermission) {
                    if ($this->registry->isCoveredBy($keptPermission, $permission)) {
                        $removed[] = $keptPermission;
                    } else {
                        $newKept[] = $keptPermission;
                    }
                }

                $kept = $newKept;
                $kept[] = $permission;
            }
        }

        sort($kept);
        sort($removed);

        return new NodeAccessPermissions(
            permissions: $kept,
            removed: array_values(array_unique($removed)),
        );
    }

    /**
     * Normalize a stored permission set that may contain stale names.
     *
     * Registry-known permissions are deduplicated and coverage-reduced as in
     * normalize(); unknown strings are preserved verbatim instead of throwing.
     * Removal paths use this so a grant holding names the registry no longer
     * recognizes stays repairable — the stale leftovers remain visible to
     * doctor rather than blocking the whole write.
     *
     * @param  list<string>  $permissions
     */
    public function normalizeKnown(array $permissions): NodeAccessPermissions
    {
        $known = [];
        $unknown = [];

        foreach ($permissions as $permission) {
            if ($this->registry->isKnown($permission)) {
                $known[] = $permission;
            } else {
                $unknown[] = $permission;
            }
        }

        $normalized = $this->normalize($known);
        $kept = [...$normalized->permissions, ...array_values(array_unique($unknown))];
        sort($kept);

        return new NodeAccessPermissions(
            permissions: $kept,
            removed: $normalized->removed,
        );
    }

    /**
     * Validate that all permissions are known.
     *
     * @param  list<string>  $permissions
     *
     * @throws InvalidArgumentException when an unknown permission is present.
     */
    public function validate(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $this->registry->isKnown($permission)) {
                throw new InvalidArgumentException("Unknown permission [{$permission}].");
            }
        }
    }
}
