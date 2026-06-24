<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\AppSetupRun;
use App\Models\AppSetupRunStep;
use App\Models\AppSetupStep;
use App\Models\Node;

final readonly class AppSetupStepRunner
{
    public function __construct(
        private RemoteShell $remoteShell,
        private AppCommandRouter $commandRouter,
    ) {}

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
    ): bool {
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

            $command = $this->commandRouter->route($app, $step->command, $environment);
            $result = $this->remoteShell->run($node, $command, [
                'cwd' => $app->path,
                'timeout' => $step->timeoutSeconds(),
                'strict' => true,
                'metadata' => $environment,
            ]);

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
