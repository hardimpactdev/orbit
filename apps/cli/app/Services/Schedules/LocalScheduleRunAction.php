<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use Symfony\Component\Process\Process;

final readonly class LocalScheduleRunAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{exit_code: int, stdout: string, stderr: string, duration_ms: int}
     */
    public function run(array $payload): array
    {
        $payload = LocalScheduleRunPayload::fromArray($payload);
        $startedAt = hrtime(true);
        $process = Process::fromShellCommandline(
            command: $payload->shellCommand(),
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
