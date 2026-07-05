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
        ], timeout: 30);

        if (! $directoryAcl->isSuccessful()) {
            throw new RuntimeException('Could not apply Orbit agent directory ACLs.');
        }

        $binaryAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:r-x',
            '/home/orbit/orbit/bin/orbit-binary',
        ], timeout: 30);

        if (! $binaryAcl->isSuccessful()) {
            throw new RuntimeException('Could not apply Orbit agent binary ACL.');
        }

        return [
            'installed_acl' => $installedAcl,
            'directory_acl_exit_code' => $directoryAcl->getExitCode(),
            'binary_acl_exit_code' => $binaryAcl->getExitCode(),
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
