<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\AppSetupRun;
use App\Models\AppSetupRunStep;
use App\Models\AppSetupStep;
use App\Models\Node;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class AppSetupStepRunner
{
    // @orbit-ssh-lane transitional-ssh
    private AppSetupStepLocalExecutor $setupStepLocalExecutor;

    public function __construct(
        private RemoteShell $remoteShell,
        private AppCommandRouter $commandRouter,
        ?RemoteLocalExecutor $localExecutor = null,
        private ExplicitRemoteShellFallback $transport = new ExplicitRemoteShellFallback,
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

            $command = $this->commandRouter->routeLifecycleForPath($app, $step->command, $app->path, $environment);
            $result = $this->transport->allowed()
                ? $this->remoteShell->run($node, $command, $this->remoteShellOptions(
                    cwd: $app->path,
                    timeout: $step->timeoutSeconds(),
                    metadata: $environment,
                ))
                : $this->setupStepLocalExecutor->run(
                    node: $node,
                    command: $command,
                    cwd: $app->path,
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

    /**
     * @param  array<string, string>  $metadata
     * @return array{cwd?: string, timeout: int, strict: true, metadata: array<string, string>}
     */
    private function remoteShellOptions(?string $cwd, int $timeout, array $metadata): array
    {
        $options = [
            'timeout' => $timeout,
            'strict' => true,
            'metadata' => $metadata,
        ];

        if ($cwd !== null && $cwd !== '') {
            $options['cwd'] = $cwd;
        }

        return $options;
    }
}
