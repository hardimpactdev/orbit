<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('node_access')->get(['id', 'permissions', 'custom_permissions']) as $grant) {
            $permissions = $this->decodePermissions($grant->permissions);
            $customPermissions = $this->decodePermissions($grant->custom_permissions);

            $nextPermissions = $this->migrateProcessEditPermission($permissions);
            $nextCustomPermissions = $this->migrateProcessEditPermission($customPermissions);

            if ($nextPermissions === $permissions && $nextCustomPermissions === $customPermissions) {
                continue;
            }

            DB::table('node_access')
                ->where('id', $grant->id)
                ->update([
                    'permissions' => json_encode($nextPermissions, JSON_THROW_ON_ERROR),
                    'custom_permissions' => json_encode($nextCustomPermissions, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        return $this->stringListFromDecodedJson(
            json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return list<string>
     */
    private function stringListFromDecodedJson(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $strings = [];

        array_walk(
            $decoded,
            static function (mixed $permission) use (&$strings): void {
                if (! is_string($permission) || $permission === '') {
                    return;
                }

                $strings[] = $permission;
            },
        );

        return $strings;
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function migrateProcessEditPermission(array $permissions): array
    {
        if (! in_array('process:edit', $permissions, true)) {
            return $permissions;
        }

        $migrated = array_map(
            fn (string $permission): string => $permission === 'process:edit' ? 'process:update' : $permission,
            $permissions,
        );

        return array_values(array_unique($migrated));
    }
};
