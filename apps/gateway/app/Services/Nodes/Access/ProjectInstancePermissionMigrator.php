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
        'app:prune' => ['instance:prune'],
        'app:setup' => ['instance:setup'],
        'app-setup-step:add' => ['instance-setup-step:add'],
        'app-setup-step:list' => ['instance-setup-step:list'],
        'app-setup-step:remove' => ['instance-setup-step:remove'],
        'app:agent' => ['instance:agent'],
        'app:root' => ['instance:root'],
        'app:update' => ['instance:update'],
        'app:new' => ['project:new'],
        'app:worker' => ['instance:worker'],
        'app:mount' => ['instance:mount'],
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

            $this->appendUnique($migrated, $permission);

            foreach (self::Replacements[$permission] ?? [] as $replacement) {
                $this->appendUnique($migrated, $replacement);
            }
        }

        return $migrated;
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
