<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Symfony\Component\Process\Process;

final readonly class LocalDoctorSelfRunner
{
    private const string ORBIT_BINARY = '/usr/local/bin/orbit';

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $operationBinary = getenv('ORBIT_BIN_PATH');
        $binary = is_string($operationBinary) ? trim($operationBinary) : '';

        if ($binary === '') {
            $invokedBinary = $_SERVER['argv'][0] ?? null;
            $binary = is_string($invokedBinary) ? trim($invokedBinary) : '';
        }

        if ($binary === '') {
            $binary = trim((string) config('orbit.local_executor_binary', self::ORBIT_BINARY));
            $binary = $binary !== '' ? $binary : self::ORBIT_BINARY;
        }

        $process = new Process([
            $binary,
            'doctor',
            '--self',
            '--stream-json',
        ]);
        $process->setTimeout(120);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'output' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
