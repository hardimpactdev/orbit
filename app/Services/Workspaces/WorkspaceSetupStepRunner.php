<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Models\Node;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;

final readonly class WorkspaceSetupStepRunner
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @param  list<WorkspaceStep>  $steps
     * @param  array<string, string>  $env
     */
    public function run(WorkspaceRun $run, array $steps, string $path, array $env, Node $node): bool
    {
        $run->update(['status' => 'running']);

        foreach ($steps as $step) {
            $runStep = WorkspaceRunStep::create([
                'workspace_run_id' => $run->id,
                'workspace_step_id' => $step->id,
                'command' => $step->command,
                'started_at' => now(),
            ]);

            $result = $this->remoteShell->run($node, $step->command, [
                'cwd' => $path,
                'timeout' => $step->timeoutSeconds(),
                'env' => $env,
            ]);

            $runStep->update([
                'exit_code' => $result->exitCode,
                'output' => $result->output(),
                'completed_at' => now(),
            ]);

            if (! $result->successful()) {
                $run->update(['status' => 'failed', 'completed_at' => now()]);

                return false;
            }
        }

        $run->update(['status' => 'completed', 'completed_at' => now()]);

        return true;
    }
}
