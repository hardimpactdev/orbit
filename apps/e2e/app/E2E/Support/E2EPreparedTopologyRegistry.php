<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EPreparedTopologyRegistry
{
    public static function appdevDatabaseAndRedisPhp(string $nodeName = 'app-dev-1'): string
    {
        $nodeNameValue = var_export($nodeName, true);

        return <<<PHP
\$node = \\App\\Models\\Node::query()->where('name', {$nodeNameValue})->firstOrFail();
\$definition = app(\\App\\Services\\Processes\\ProcessServiceDefinitionRegistry::class)->resolve(
    definition: 'redis',
    version: '7',
    runtime: \\App\\Enums\\Processes\\ProcessRuntime::Docker,
    node: \$node,
    processName: 'redis',
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

\\App\\Models\\Process::query()->updateOrCreate(
    [
        'owner_type' => \$node->getMorphClass(),
        'owner_id' => \$node->id,
        'name' => 'redis',
    ],
    [
        'node_id' => \$node->id,
        'command' => \$definition->command,
        'restart_policy' => \\App\\Enums\\ProcessRestartPolicy::Always->value,
        'crash_notification' => \\App\\Enums\\ProcessCrashNotification::None->value,
        'runtime' => \\App\\Enums\\Processes\\ProcessRuntime::Docker->value,
        'tool' => null,
        'runtime_config' => \$definition->runtimeConfig,
        'sort_order' => 10,
    ],
);
PHP;
    }
}
