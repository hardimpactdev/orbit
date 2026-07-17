<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EPreparedTopologyRegistry
{
    public static function appdevDatabaseAndValkeyPhp(
        string $nodeName = 'app-dev-1',
        bool $convergeRuntime = false,
    ): string {
        $nodeNameValue = var_export($nodeName, true);
        $removeLegacyRuntime = $convergeRuntime
            ? "                app(\\App\\Services\\Nodes\\Roles\\RoleBaselines\\RoleRuntimeConverger::class)->removeProcess(\$node, \$legacyRedis, 'redis');\n"
            : '';
        $convergeValkeyRuntime = $convergeRuntime
            ? "\n            app(\\App\\Services\\Nodes\\Roles\\RoleBaselines\\RoleRuntimeConverger::class)->convergeProcess(\$node, \$valkey, 'valkey');"
            : '';

        return <<<PHP
            \$node = \\App\\Models\\Node::query()->where('name', {$nodeNameValue})->firstOrFail();
            \$legacyRedisProcesses = \\App\\Models\\Process::query()
                ->ownedBy(\$node)
                ->where('runtime_config->service', 'redis')
                ->get();

            foreach (\$legacyRedisProcesses as \$legacyRedis) {
            {$removeLegacyRuntime}                \$legacyRedis->delete();
            }

            \$valkeyDescriptor = app(\\App\\Services\\Processes\\ProcessServiceCatalog::class)->resolve(
                service: 'valkey',
                version: '8',
                runtime: \\App\\Enums\\Processes\\ProcessRuntime::Docker,
                node: \$node,
                processName: 'valkey',
            );

            \\App\\Models\\NodeRoleAssignment::query()->updateOrCreate(
                [
                    'node_id' => \$node->id,
                    'role' => \\App\\Enums\\Nodes\\NodeRoleName::Database->value,
                ],
                [
                    'status' => \\App\\Enums\\Nodes\\NodeRoleStatus::Active->value,
                    'settings' => [],
                    'last_error' => null,
                    'converged_at' => now(),
                ],
            );

            \$valkey = \\App\\Models\\Process::query()->updateOrCreate(
                [
                    'owner_type' => \$node->getMorphClass(),
                    'owner_id' => \$node->id,
                    'name' => 'valkey',
                ],
                [
                    'node_id' => \$node->id,
                    'command' => \$valkeyDescriptor->command,
                    'restart_policy' => \\App\\Enums\\ProcessRestartPolicy::Always->value,
                    'crash_notification' => \\App\\Enums\\ProcessCrashNotification::None->value,
                    'runtime' => \\App\\Enums\\Processes\\ProcessRuntime::Docker->value,
                    'tool' => null,
                    'runtime_config' => \$valkeyDescriptor->runtimeConfig,
                    'sort_order' => 10,
                ],
            );{$convergeValkeyRuntime}
            PHP;
    }
}
