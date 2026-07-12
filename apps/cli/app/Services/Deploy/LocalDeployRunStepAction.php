<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use Symfony\Component\Process\Process;

final readonly class LocalDeployRunStepAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{exit_code: int, stdout: string, stderr: string, duration_ms: int}
     */
    public function run(array $payload): array
    {
        $payload = LocalDeployRunStepPayload::fromArray($payload);
        $startedAt = hrtime(true);
        $process = new Process(
            command: [$payload->binary, ...$payload->arguments],
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
