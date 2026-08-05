<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic App → Instance vocabulary cutover for concrete runtime storage and grants.
 *
 * Historical migration files remain byte-stable. Project workload permission tokens
 * and live activity_log JSON property keys are rewritten here once only; runtime
 * code does not dual-read, dual-store, or translate Project/AppInstance workload keys.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
return new class extends Migration {
    /**
     * One-time Project workload → App permission rewrite (migration-local).
     * Runtime grant paths must not retain these replacements; only this migration rewrites them.
     *
     * @var array<string, string>
     */
    private const array ProjectPermissionRewrites = [
        'project:*' => 'app:*',
        'project:list' => 'app:list',
        'project:show' => 'app:show',
        'project:read' => 'app:read',
        'project:write' => 'app:write',
        'project:remove' => 'app:remove',
        'project:new' => 'app:new',
        'project:update' => 'app:update',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->renameConcreteRuntimeTablesAndColumns();
            $this->rewriteStoredDriverMorphTypes();
            $this->rewriteNodeAccessProjectPermissionsToApp();
            $this->rewriteActivityLogWorkloadProperties();
        });
    }

    public function down(): void
    {
        // One-way cutover: recovery is restore-from-backup, not dual-schema rollback.
    }

    private function renameConcreteRuntimeTablesAndColumns(): void
    {
        if (Schema::hasTable('app_instances') && ! Schema::hasTable('instances')) {
            Schema::rename('app_instances', 'instances');
        }

        if (Schema::hasTable('app_instance_env_variables') && ! Schema::hasTable('instance_env_variables')) {
            Schema::rename('app_instance_env_variables', 'instance_env_variables');
        }

        if (Schema::hasTable('app_instance_runtime_mounts') && ! Schema::hasTable('instance_runtime_mounts')) {
            Schema::rename('app_instance_runtime_mounts', 'instance_runtime_mounts');
        }

        $tables = [
            'instance_env_variables',
            'instance_runtime_mounts',
            'workspaces',
            'workspace_steps',
            'processes',
            'process_events',
            'schedules',
            'deploy_steps',
            'deployment_runs',
            'app_setup_steps',
            'app_setup_runs',
            'database_connection_targets',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'app_instance_id') && ! Schema::hasColumn($table, 'instance_id')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->renameColumn('app_instance_id', 'instance_id');
                });
            }
        }
    }

    private function rewriteStoredDriverMorphTypes(): void
    {
        if (! Schema::hasTable('instances') || ! Schema::hasColumn('instances', 'driver_config')) {
            return;
        }

        foreach (DB::table('instances')->select(['id', 'driver_config'])->get() as $row) {
            if (! is_string($row->driver_config) || $row->driver_config === '') {
                continue;
            }

            $updated = str_replace(
                [
                    'orbit_app_instance_driver_config',
                    'laravel_cloud_app_instance_driver_config',
                ],
                [
                    'orbit_instance_driver_config',
                    'laravel_cloud_instance_driver_config',
                ],
                $row->driver_config,
            );

            if ($updated === $row->driver_config) {
                continue;
            }

            DB::table('instances')->where('id', $row->id)->update(['driver_config' => $updated]);
        }
    }

    private function rewriteNodeAccessProjectPermissionsToApp(): void
    {
        if (! Schema::hasTable('node_access')) {
            return;
        }

        foreach (DB::table('node_access')->get(['id', 'permissions', 'custom_permissions']) as $grant) {
            $permissions = $this->decodePermissions($grant->permissions);
            $customPermissions = $this->decodePermissions($grant->custom_permissions);
            $migratedPermissions = $this->rewriteProjectTokens($permissions);
            $migratedCustomPermissions = $this->rewriteProjectTokens($customPermissions);

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
    }

    private function rewriteActivityLogWorkloadProperties(): void
    {
        if (! Schema::hasTable('activity_log') || ! Schema::hasColumn('activity_log', 'properties')) {
            return;
        }

        foreach (DB::table('activity_log')->select(['id', 'properties'])->orderBy('id')->get() as $row) {
            if ($row->properties === null) {
                continue;
            }

            if (! is_string($row->properties) || $row->properties === '') {
                throw new \UnexpectedValueException(
                    "activity_log.properties for id {$row->id} must be a non-empty JSON object string.",
                );
            }

            $decoded = json_decode($row->properties, associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new \UnexpectedValueException(
                    "activity_log.properties for id {$row->id} must decode to a JSON object.",
                );
            }

            /** @var array<string, mixed> $properties */
            $properties = $decoded;
            $rewritten = $this->rewriteWorkloadPropertyKeys($properties);

            if ($rewritten === $decoded) {
                continue;
            }

            DB::table('activity_log')
                ->where('id', $row->id)
                ->update([
                    'properties' => json_encode($rewritten, JSON_THROW_ON_ERROR),
                ]);
        }
    }

    /**
     * One-way Project/AppInstance workload keys → App/Instance in stored JSON.
     * Does not alter security host-key metadata fields (type/fingerprint/etc.).
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function rewriteWorkloadPropertyKeys(array $properties): array
    {
        if (array_key_exists('project', $properties) && ! array_key_exists('app', $properties)) {
            $properties['app'] = $properties['project'];
        }

        if (array_key_exists('project_name', $properties) && ! array_key_exists('app_name', $properties)) {
            $properties['app_name'] = $properties['project_name'];
        }

        if (array_key_exists('app_instance', $properties) && ! array_key_exists('instance', $properties)) {
            $properties['instance'] = $properties['app_instance'];
        }

        unset($properties['project'], $properties['project_name'], $properties['app_instance']);

        return $properties;
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function rewriteProjectTokens(array $permissions): array
    {
        /** @var list<string> $rewritten */
        $rewritten = [];

        foreach ($permissions as $permission) {
            $token = self::ProjectPermissionRewrites[$permission] ?? $permission;

            if (! in_array($token, $rewritten, true)) {
                $rewritten[] = $token;
            }
        }

        return $rewritten;
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

        if (array_filter($decoded, static fn (mixed $permission): bool => ! is_string($permission)) !== []) {
            throw new \UnexpectedValueException('Stored node permissions must contain only strings.');
        }

        /** @var list<string> $decoded */
        return $decoded;
    }
};
