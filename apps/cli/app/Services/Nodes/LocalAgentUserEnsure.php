<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class LocalAgentUserEnsure
{
    private const string AGENT_USER = 'agent';

    /**
     * @return array<string, mixed>
     */
    public function ensure(): array
    {
        $probe = $this->run(['id', '-u', self::AGENT_USER], timeout: 10);
        $created = false;

        if (! $probe->isSuccessful()) {
            $create = $this->run([
                'sudo',
                '-n',
                'useradd',
                '--create-home',
                '--shell',
                '/bin/bash',
                self::AGENT_USER,
            ], timeout: 30);

            if (! $create->isSuccessful()) {
                throw new RuntimeException('Could not create the Orbit agent runtime user.');
            }

            $created = true;
        }

        $lock = $this->run(['sudo', '-n', 'passwd', '-l', self::AGENT_USER], timeout: 30);

        return [
            'user' => self::AGENT_USER,
            'created' => $created,
            'lock_exit_code' => $lock->getExitCode(),
            'locked' => $lock->isSuccessful(),
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout): Process
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
