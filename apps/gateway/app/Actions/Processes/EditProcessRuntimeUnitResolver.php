<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessOwnerContext;

final readonly class EditProcessRuntimeUnitResolver
{
    private const array FIXED_RUNTIME_UNIT_NAME_KEYS = [
        'docker' => 'container_name',
        'docker-swarm' => 'service_name',
    ];

    public function fixedRuntimeUnitName(Process $process): ?string
    {
        $config = $process->runtime_config;
        $key = self::FIXED_RUNTIME_UNIT_NAME_KEYS[$process->runtime->value] ?? null;

        if ($key === null || ! array_key_exists($key, $config) || ! is_string($config[$key])) {
            return null;
        }

        $name = trim($config[$key]);

        return $name !== '' ? $name : null;
    }

    /**
     * @param  array{name: string, context: string}  $runtimeUnit
     */
    public function runtimeWorkspaceForUnit(
        ProcessOwnerContext $context,
        App $app,
        Process $process,
        array $runtimeUnit,
    ): ?Workspace {
        if ($process->owner instanceof Workspace) {
            return $process->owner;
        }

        if ($runtimeUnit['context'] === 'node' || $runtimeUnit['context'] === 'main') {
            return null;
        }

        if ($context->workspace instanceof Workspace) {
            return $context->workspace;
        }

        $workspace = $app->workspaces->firstWhere('name', $runtimeUnit['context']);

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
