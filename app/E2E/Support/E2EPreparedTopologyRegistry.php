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

\\App\\Models\\NodeTool::query()->updateOrCreate(
    [
        'node_id' => \$node->id,
        'name' => 'redis',
    ],
    [
        'expected_state' => 'running',
        'expected_version' => null,
        'config' => ['endpoints' => []],
        'credentials' => null,
    ],
);
PHP;
    }
}
