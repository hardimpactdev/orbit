<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Models\AppSetupRun;
use App\Models\AppSetupRunStep;
use App\Models\AppSetupStep;
use App\Models\Instance;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppSetupStepRunner
{
    private AppSetupStepLocalExecutor $setupStepLocalExecutor;

    public function __construct(
        private AppCommandRouter $commandRouter,
        ?RunsInternalCommands $localExecutor = null,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {
        $this->setupStepLocalExecutor = new AppSetupStepLocalExecutor($localExecutor);
    }

    /**
     * @param  list<AppSetupStep>  $steps
     * @param  array<string, string>  $environment
     * @param  (callable(string, AppSetupStep, int, int): void)|null  $onProgress
     */
    public function run(
        AppSetupRun $run,
        array $steps,
        App $app,
        Node $node,
        array $environment,
        ?callable $onProgress = null,
        ?Instance $instance = null,
    ): bool {
        $appPath = $this->placement->runtimePath($app, $instance);
        $run->update(['status' => 'running']);
        $stepCount = count($steps);

        foreach (array_values($steps) as $index => $step) {
            $runStep = AppSetupRunStep::query()->create([
                'app_setup_run_id' => $run->id,
                'app_setup_step_id' => $step->id,
                'command' => $step->command,
                'started_at' => now(),
            ]);

            if ($onProgress !== null) {
                $onProgress('running', $step, $index + 1, $stepCount);
            }

            $command = $this->commandRouter->routeLifecycleForPath(
                $app,
                $step->command,
                $appPath,
                $environment,
                $instance,
            );
            $result = $this->setupStepLocalExecutor->run(
                node: $node,
                command: $command,
                cwd: $appPath,
                timeout: $step->timeoutSeconds(),
                environment: $environment,
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
