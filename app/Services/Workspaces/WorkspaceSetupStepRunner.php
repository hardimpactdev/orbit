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
     * @param  (callable(string, WorkspaceStep, int, int): void)|null  $onProgress
     * @param  array{vendor?: bool, node_modules?: bool}  $linkedDependencies
     */
    public function run(
        WorkspaceRun $run,
        array $steps,
        string $path,
        array $env,
        Node $node,
        ?callable $onProgress = null,
        array $linkedDependencies = [],
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

            $skipMessage = $this->dependencyInstallSkipMessage($step, $linkedDependencies);

            if ($skipMessage !== null) {
                $runStep->update([
                    'exit_code' => 0,
                    'output' => $skipMessage,
                    'completed_at' => now(),
                ]);

                if ($onProgress !== null) {
                    $onProgress('completed', $step, $index + 1, $stepCount);
                }

                continue;
            }

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

    /**
     * @param  array{vendor?: bool, node_modules?: bool}  $linkedDependencies
     */
    private function dependencyInstallSkipMessage(WorkspaceStep $step, array $linkedDependencies): ?string
    {
        $command = trim($step->command);

        if (($linkedDependencies['vendor'] ?? false) && preg_match('/^(?:composer|php\s+composer(?:\.phar)?)\s+install(?:\s|$)/', $command) === 1) {
            return 'Skipped because the workspace uses the app vendor directory.';
        }

        if (
            ($linkedDependencies['node_modules'] ?? false)
            && preg_match('/^(?:(?:npm|pnpm|yarn|bun)\s+(?:ci|install|i|add))(?:\s|$)/', $command) === 1
        ) {
            return 'Skipped because the workspace uses the app node_modules directory.';
        }

        return null;
    }
}
