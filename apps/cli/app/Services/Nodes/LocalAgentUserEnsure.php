<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class LocalAgentUserEnsure
{
    private const string AGENT_USER = 'agent';

    private const string AGENT_SHIM_PATH = '/home/agent/.local/bin/orbit';

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
        $shim = $this->ensureShim();

        return [
            'user' => self::AGENT_USER,
            'created' => $created,
            'lock_exit_code' => $lock->getExitCode(),
            'locked' => $lock->isSuccessful(),
            'shim_path' => self::AGENT_SHIM_PATH,
            'shim_installed' => $shim->isSuccessful(),
        ];
    }

    private function ensureShim(): Process
    {
        $shim = tempnam(sys_get_temp_dir(), prefix: 'orbit-agent-shim-');

        if (! is_string($shim)) {
            throw new RuntimeException('Could not create temporary Orbit agent shim.');
        }

        if (file_put_contents($shim, $this->shimContents()) === false || ! chmod(filename: $shim, permissions: 0o755)) {
            $this->removeTemporaryShim($shim);

            throw new RuntimeException('Could not write the Orbit agent runtime user shim.');
        }

        $directory = $this->run([
            'sudo',
            '-n',
            'install',
            '-d',
            '-m',
            '0755',
            dirname(self::AGENT_SHIM_PATH),
        ], timeout: 30);

        if (! $directory->isSuccessful()) {
            $this->removeTemporaryShim($shim);

            throw new RuntimeException('Could not create the Orbit agent shim directory.');
        }

        $install = $this->run([
            'sudo',
            '-n',
            'install',
            '-m',
            '0755',
            $shim,
            self::AGENT_SHIM_PATH,
        ], timeout: 30);

        $this->removeTemporaryShim($shim);

        if (! $install->isSuccessful()) {
            throw new RuntimeException('Could not install the Orbit agent runtime user shim.');
        }

        return $install;
    }

    private function removeTemporaryShim(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        unlink($path);
    }

    private function shimContents(): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            export ORBIT_CONFIG_PATH=/home/orbit/.config/orbit/config.json
            export ORBIT_INSTALL_METADATA_PATH=/home/orbit/.config/orbit/install.json

            exec /home/orbit/.local/bin/orbit "$@"
            BASH;
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
