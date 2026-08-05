<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity
 *
 * Historical migration support for
 * `2026_07_20_080355_add_project_instance_permissions_to_node_access_grants` only.
 *
 * Do not inject this class into runtime grant read/write paths. After the
 * 2026-08-05 App → Instance cutover, runtime consumes only registry-known
 * canonical permissions through NodePermissionRegistry / NodePermissionNormalizer.
 *
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
        return array_values($migrated);
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
