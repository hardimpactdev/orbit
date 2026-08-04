<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final class ProjectInstancePermissionMigrator
{
    /**
     * @var array<string, list<string>>
     */
    private const array Replacements = [
        'app:*' => ['project:*', 'instance:*'],
        'app:credentials' => ['instance:credentials'],
        'app:list' => ['project:list'],
        'app:show' => ['project:show'],
        'app:read' => ['project:read', 'instance:read'],
        'app:write' => ['project:write', 'instance:write'],
        'app:register' => ['instance:register'],
        'app:remove' => ['project:remove'],
        'app:setup' => ['instance:setup'],
        'app-setup-step:add' => ['instance-setup-step:add'],
        'app-setup-step:list' => ['instance-setup-step:list'],
        'app-setup-step:remove' => ['instance-setup-step:remove'],
        'app:root' => ['instance:root'],
        'app:update' => ['instance:update'],
        'app:new' => ['project:new'],
        'app:worker' => ['instance:worker'],
        'app:mount' => ['instance:mount'],
    ];

    /**
     * Removed ADE/OpenCode permissions and predecessors. Never expand or retain.
     *
     * @var list<string>
     */
    private const array RemovedPermissions = [
        'agent-ide:*',
        'agent-ide:message',
        'instance:agent',
        'node:agent',
        'instance:prune',
        'app:agent',
        'app:prune',
    ];

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public function migrate(array $permissions): array
    {
        /** @var list<string> $migrated */
        $migrated = [];

        foreach ($permissions as $permission) {
            if (! is_string($permission) || $permission === '') {
                throw new InvalidArgumentException('Permissions must be non-empty strings.');
            }

            if ($this->isRemoved($permission)) {
                continue;
            }

            $this->appendUnique($migrated, $permission);

            foreach (self::Replacements[$permission] ?? [] as $replacement) {
                if ($this->isRemoved($replacement)) {
                    continue;
                }

                $this->appendUnique($migrated, $replacement);
            }
        }

        /** @var list<string> $migrated */
        $migrated = array_values($migrated);

        return $migrated;
    }

    /**
     * Return the canonical permissions understood and exposed by the current runtime.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public function current(array $permissions): array
    {
        $containsLegacyPermission = array_any(
            $permissions,
            fn (string $permission): bool => (
                array_key_exists($permission, self::Replacements) || $this->isRemoved($permission)
            ),
        );
        $currentPermissions = array_values(array_filter(
            $this->migrate($permissions),
            fn (string $permission): bool => (
                ! array_key_exists($permission, self::Replacements) && ! $this->isRemoved($permission)
            ),
        ));

        if ($containsLegacyPermission) {
            sort($currentPermissions);
        }

        return $currentPermissions;
    }

    /**
     * Keep rollback tokens only while their replacement permissions remain granted.
     *
     * @param  list<string>  $storedPermissions
     * @param  list<string>  $currentPermissions
     * @return list<string>
     */
    public function forStorage(
        array $storedPermissions,
        array $currentPermissions,
        NodePermissionRegistry $registry,
    ): array {
        /** @var list<string> $storagePermissions */
        $storagePermissions = [];

        foreach ($storedPermissions as $permission) {
            $replacements = self::Replacements[$permission] ?? null;

            if (
                $replacements !== null
                && array_all(
                    $replacements,
                    static fn (string $replacement): bool => $registry->allows($currentPermissions, $replacement),
                )
            ) {
                $this->appendUnique($storagePermissions, $permission);
            }
        }

        foreach ($currentPermissions as $permission) {
            $this->appendUnique($storagePermissions, $permission);
        }

        /** @var list<string> $storagePermissions */
        $storagePermissions = array_values($storagePermissions);

        return $storagePermissions;
    }

    private function isRemoved(string $permission): bool
    {
        return in_array($permission, self::RemovedPermissions, true);
    }

    private function appendUnique(array &$permissions, string $permission): void
    {
        if (! in_array($permission, $permissions, true)) {
            $permissions[] = $permission;
        }
    }
}
