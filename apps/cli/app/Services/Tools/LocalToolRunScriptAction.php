<?php

declare(strict_types=1);

namespace App\Services\Tools;

use Symfony\Component\Process\Process;

final readonly class LocalToolRunScriptAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{exit_code: int, stdout: string, stderr: string, duration_ms: int}
     */
    public function run(array $payload): array
    {
        $payload = LocalToolRunScriptPayload::fromArray($payload);
        $startedAt = hrtime(true);
        $process = new Process(
            ['bash', '-c', $payload->script],
            LocalToolRunScriptPayload::FIXED_CWD,
            [],
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
