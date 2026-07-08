<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Models\Node;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class WorkspaceSetupStepRunner
{
    private WorkspaceSetupStepLocalExecutor $setupStepLocalExecutor;

    public function __construct(
        private RemoteShell $remoteShell,
        ?RemoteLocalExecutor $localExecutor = null,
        private ExplicitRemoteShellFallback $transport = new ExplicitRemoteShellFallback,
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
        ?string $containerName = null,
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

            $isContainerized = $containerName !== null && $this->isPhpCommand($step->command);
            $command = $isContainerized
                ? $this->containerCommand($step->command, $containerName, $env)
                : $step->command;

            $result = $this->transport->allowed()
                ? $this->remoteShell->run($node, $command, $this->remoteShellOptions(
                    cwd: $isContainerized ? null : $path,
                    timeout: $step->timeoutSeconds(),
                    metadata: $env,
                ))
                : $this->setupStepLocalExecutor->run(
                    node: $node,
                    command: $command,
                    cwd: $isContainerized ? null : $path,
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

    /**
     * @param  array<string, string>  $metadata
     * @return array{cwd?: string, timeout: int, metadata: array<string, string>}
     */
    private function remoteShellOptions(?string $cwd, int $timeout, array $metadata): array
    {
        $options = [
            'timeout' => $timeout,
            'metadata' => $metadata,
        ];

        if ($cwd !== null && $cwd !== '') {
            $options['cwd'] = $cwd;
        }

        return $options;
    }

    private function isPhpCommand(string $command): bool
    {
        $trimmed = ltrim($command);

        return str_starts_with($trimmed, 'php ') || str_starts_with($trimmed, 'composer ');
    }

    /**
     * @param  array<string, string>  $env
     */
    private function containerCommand(string $command, string $containerName, array $env): string
    {
        $parts = ['docker', 'exec', '-w', '/app'];

        foreach ($env as $key => $value) {
            $parts[] = '-e';
            $parts[] = "{$key}={$value}";
        }

        $parts[] = $containerName;
        $parts[] = 'bash';
        $parts[] = '-c';
        $parts[] = $command;

        return implode(' ', array_map(escapeshellarg(...), $parts));
    }
}
