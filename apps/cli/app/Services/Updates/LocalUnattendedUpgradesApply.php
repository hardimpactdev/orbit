<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Symfony\Component\Process\Process;

final readonly class LocalUnattendedUpgradesApply
{
    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $result = $this->process(['sudo', 'unattended-upgrade'], timeout: 900);

        if (! $result->isSuccessful()) {
            throw new LocalUnattendedUpgradesApplyFailure(
                errorCode: 'unattended_upgrades_failed',
                message: 'Failed to run unattended-upgrades.',
                meta: [
                    'exit_code' => $result->getExitCode(),
                    'stderr' => trim($result->getErrorOutput()),
                ],
            );
        }

        return [
            'exit_code' => $result->getExitCode(),
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function process(array $command, int $timeout): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
