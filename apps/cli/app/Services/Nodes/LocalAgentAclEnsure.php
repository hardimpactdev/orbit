<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class LocalAgentAclEnsure
{
    /**
     * @return array<string, mixed>
     */
    public function ensure(): array
    {
        $probe = $this->run(['setfacl', '--version'], timeout: 10);
        $installedAcl = false;

        if (! $probe->isSuccessful()) {
            $update = $this->run(['sudo', 'apt-get', 'update'], timeout: 120);

            if (! $update->isSuccessful()) {
                throw new RuntimeException('Could not update package indexes before installing ACL support.');
            }

            $install = $this->run([
                'sudo',
                'env',
                'DEBIAN_FRONTEND=noninteractive',
                'apt-get',
                'install',
                '-y',
                'acl',
            ], timeout: 120);

            if (! $install->isSuccessful()) {
                throw new RuntimeException('Could not install ACL support for the Orbit agent runtime user.');
            }

            $installedAcl = true;
        }

        $directoryAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:--x',
            '/home/orbit',
            '/home/orbit/orbit',
            '/home/orbit/orbit/bin',
            '/home/orbit/.config',
            '/home/orbit/.config/orbit',
            '/home/orbit/.local',
            '/home/orbit/.local/bin',
        ], timeout: 30);

        if (! $directoryAcl->isSuccessful()) {
            throw new RuntimeException('Could not apply Orbit agent directory ACLs.');
        }

        $configAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:r--',
            '/home/orbit/.config/orbit/config.json',
            '/home/orbit/.config/orbit/install.json',
        ], timeout: 30);

        if (! $configAcl->isSuccessful()) {
            throw new RuntimeException('Could not apply Orbit agent config ACL.');
        }

        $binaryAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:r-x',
            '/home/orbit/.local/bin/orbit',
        ], timeout: 30);

        if (! $binaryAcl->isSuccessful()) {
            throw new RuntimeException('Could not apply Orbit agent binary ACL.');
        }

        $agentBinaryAcl = $this->run([
            'sh',
            '-lc',
            'if [ -e /home/orbit/.local/bin/orbit-agent ]; then exec sudo setfacl -m u:agent:r-x /home/orbit/.local/bin/orbit-agent; fi',
        ], timeout: 30);

        if (! $agentBinaryAcl->isSuccessful()) {
            throw new RuntimeException('Could not apply Orbit agent binary ACL.');
        }

        return [
            'installed_acl' => $installedAcl,
            'directory_acl_exit_code' => $directoryAcl->getExitCode(),
            'config_acl_exit_code' => $configAcl->getExitCode(),
            'binary_acl_exit_code' => $binaryAcl->getExitCode(),
            'agent_binary_acl_exit_code' => $agentBinaryAcl->getExitCode(),
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
