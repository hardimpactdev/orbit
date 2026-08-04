<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use InvalidArgumentException;

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
    private const array Removed = [
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
        $migrated = [];

        foreach ($permissions as $permission) {
            if (! is_string($permission) || $permission === '') {
                throw new InvalidArgumentException('Permissions must be non-empty strings.');
            }

            if (in_array($permission, self::Removed, true)) {
                continue;
            }

            $this->appendUnique($migrated, $permission);

            foreach (self::Replacements[$permission] ?? [] as $replacement) {
                if (in_array($replacement, self::Removed, true)) {
                    continue;
                }

                $this->appendUnique($migrated, $replacement);
            }
        }

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
            static fn (string $permission): bool => (
                array_key_exists($permission, self::Replacements)
                || in_array($permission, self::Removed, true)
            ),
        );
        $currentPermissions = array_values(array_filter(
            $this->migrate($permissions),
            static fn (string $permission): bool => (
                ! array_key_exists($permission, self::Replacements)
                && ! in_array($permission, self::Removed, true)
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

        return $storagePermissions;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function appendUnique(array &$permissions, string $permission): void
    {
        if (! in_array($permission, $permissions, true)) {
            $permissions[] = $permission;
        }
    }
}
