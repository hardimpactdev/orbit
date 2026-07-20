<?php

declare(strict_types=1);

use App\Services\Nodes\Access\ProjectInstancePermissionMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            $migrator = new ProjectInstancePermissionMigrator;

            foreach (DB::table('node_access')->get(['id', 'permissions', 'custom_permissions']) as $grant) {
                $permissions = $this->decodePermissions($grant->permissions);
                $customPermissions = $this->decodePermissions($grant->custom_permissions);
                $migratedPermissions = $migrator->migrate($permissions);
                $migratedCustomPermissions = $migrator->migrate($customPermissions);

                if ($migratedPermissions === $permissions && $migratedCustomPermissions === $customPermissions) {
                    continue;
                }

                DB::table('node_access')
                    ->where('id', $grant->id)
                    ->update([
                        'permissions' => json_encode($migratedPermissions, JSON_THROW_ON_ERROR),
                        'custom_permissions' => json_encode($migratedCustomPermissions, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // The old permission tokens are retained, so rolling back needs no data mutation.
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $value): array
    {
        if (! is_string($value)) {
            throw new \UnexpectedValueException('Stored node permissions must be JSON strings.');
        }

        $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new \UnexpectedValueException('Stored node permissions must be JSON lists.');
        }

        if (array_filter($decoded, fn (mixed $permission): bool => ! is_string($permission)) !== []) {
            throw new \UnexpectedValueException('Stored node permissions must contain only strings.');
        }

        /** @var list<string> $decoded */
        return $decoded;
    }
};
