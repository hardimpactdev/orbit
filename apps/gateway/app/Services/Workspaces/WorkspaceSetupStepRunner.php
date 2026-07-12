<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\App;
use App\Models\Node;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use App\Services\Apps\AppCommandRouter;
use App\Services\RemoteShell\RunsInternalCommands;

final readonly class WorkspaceSetupStepRunner
{
    private WorkspaceSetupStepLocalExecutor $setupStepLocalExecutor;

    public function __construct(
        ?RunsInternalCommands $localExecutor = null,
        private AppCommandRouter $commandRouter = new AppCommandRouter,
    ) {
        $this->setupStepLocalExecutor = new WorkspaceSetupStepLocalExecutor($localExecutor);
    }

    /**
     * @param  list<WorkspaceStep>  $steps
     * @param  array<string, string>  $env
     * @param  (callable(string, WorkspaceStep, int, int): void)|null  $onProgress
     */
    public function run(
        WorkspaceRun $run,
        array $steps,
        string $path,
        array $env,
        Node $node,
        ?App $app = null,
        ?callable $onProgress = null,
    ): bool {
        $run->update(['status' => 'running']);
        $stepCount = count($steps);

        foreach (array_values($steps) as $index => $step) {
            $runStep = WorkspaceRunStep::create([
                'workspace_run_id' => $run->id,
                'workspace_step_id' => $step->id,
                'command' => $step->command,
                'started_at' => now(),
            ]);

            if ($onProgress !== null) {
                $onProgress('running', $step, $index + 1, $stepCount);
            }

            $command = $app instanceof App
                ? $this->commandRouter->routeLifecycleForNodePath($app, $node, $step->command, $path, $env)
                : $step->command;

            $result = $this->setupStepLocalExecutor->run(
                node: $node,
                command: $command,
                cwd: $path,
                timeout: $step->timeoutSeconds(),
                environment: $env,
            );

            $runStep->update([
                'exit_code' => $result->exitCode,
                'output' => $result->output(),
                'completed_at' => now(),
            ]);

            if (! $result->successful()) {
                if ($onProgress !== null) {
                    $onProgress('failed', $step, $index + 1, $stepCount);
                }

                $run->update(['status' => 'failed', 'completed_at' => now()]);

                return false;
            }

            if ($onProgress !== null) {
                $onProgress('completed', $step, $index + 1, $stepCount);
            }
        }

        $run->update(['status' => 'completed', 'completed_at' => now()]);

        return true;
    }
}
