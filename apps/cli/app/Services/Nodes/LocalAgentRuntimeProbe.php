<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use Symfony\Component\Process\Process;

final readonly class LocalAgentRuntimeProbe
{
    private const string AGENT_USER = 'agent';

    private const string ORBIT_BINARY = '/usr/local/bin/orbit';

    private const string ORBIT_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $user = $this->run(['id', '-u', self::AGENT_USER], timeout: 10);

        if (! $user->isSuccessful()) {
            return [
                'runtime_user' => false,
                'orbit_cli' => false,
                'runtime_user_exit_code' => $user->getExitCode(),
                'orbit_cli_exit_code' => null,
            ];
        }

        $cli = $this->run([
            'sudo',
            '-n',
            '-u',
            self::AGENT_USER,
            '-H',
            '/usr/bin/env',
            'PATH='.self::ORBIT_PATH,
            self::ORBIT_BINARY,
            '--version',
            '--local',
        ], timeout: 10);

        return [
            'runtime_user' => true,
            'orbit_cli' => $cli->isSuccessful(),
            'runtime_user_exit_code' => $user->getExitCode(),
            'orbit_cli_exit_code' => $cli->getExitCode(),
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
