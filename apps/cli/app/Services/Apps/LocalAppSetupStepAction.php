<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Services\Workspaces\LocalWorkspaceSetupStepPayload;
use Symfony\Component\Process\Process;

final readonly class LocalAppSetupStepAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{exit_code: int, stdout: string, stderr: string, duration_ms: int}
     */
    public function run(array $payload): array
    {
        $payload = LocalWorkspaceSetupStepPayload::fromArray($payload);
        $startedAt = hrtime(true);
        $process = Process::fromShellCommandline(
            command: $payload->command,
            cwd: $payload->cwd,
            env: $payload->environment,
        );
        $process->setTimeout($payload->timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }
}
