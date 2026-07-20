<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\AppInstance;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use InvalidArgumentException;

final class ProcessRuntimeUnitName
{
    public static function for(Project $app, Process $process, ?Workspace $workspace = null): string
    {
        $process->loadMissing('appInstance');
        $instance = $process->appInstance;

        if (! $instance instanceof AppInstance) {
            throw new InvalidArgumentException(
                "Process '{$process->name}' has no concrete instance for runtime-unit identity.",
            );
        }

        if ($workspace instanceof Workspace && $workspace->app_instance_id !== $instance->id) {
            throw new InvalidArgumentException(
                "Process '{$process->name}' cannot render for a workspace on another instance.",
            );
        }

        $scope = $workspace->name ?? 'main';

        return "orbit_{$app->name}_{$instance->name}_{$scope}_{$process->name}";
    }
}
