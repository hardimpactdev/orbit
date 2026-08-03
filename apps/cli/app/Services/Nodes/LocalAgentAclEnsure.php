<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class LocalAgentAclEnsure
{
    /**
     * Canonical installed CLI/config paths that the agent role probe requires.
     *
     * @var list<string>
     */
    private const array REQUIRED_DIRECTORY_PATHS = [
        '/home/orbit',
        '/home/orbit/.config',
        '/home/orbit/.config/orbit',
        '/home/orbit/.local',
        '/home/orbit/.local/bin',
    ];

    /**
     * Optional legacy/source-checkout paths. Absent entries are skipped so
     * installed-only nodes converge without requiring a source tree.
     *
     * @var list<string>
     */
    private const array OPTIONAL_DIRECTORY_PATHS = [
        '/home/orbit/orbit',
        '/home/orbit/orbit/bin',
    ];

    /**
     * @var list<string>
     */
    private const array REQUIRED_CONFIG_PATHS = [
        '/home/orbit/.config/orbit/config.json',
        '/home/orbit/.config/orbit/install.json',
    ];

    private const string REQUIRED_BINARY_PATH = '/home/orbit/.local/bin/orbit';

    private const string OPTIONAL_AGENT_BINARY_PATH = '/home/orbit/.local/bin/orbit-agent';

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
                throw new RuntimeException(
                    'Could not ensure Orbit agent runtime ACLs (stage=package_index).',
                );
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
                throw new RuntimeException(
                    'Could not ensure Orbit agent runtime ACLs (stage=acl_package_install).',
                );
            }

            $installedAcl = true;
        }

        $directoryAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:--x',
            ...self::REQUIRED_DIRECTORY_PATHS,
        ], timeout: 30);

        if (! $directoryAcl->isSuccessful()) {
            throw new RuntimeException(
                'Could not ensure Orbit agent runtime ACLs (stage=directory_acl).',
            );
        }

        $optionalDirectoryPaths = [];

        foreach (self::OPTIONAL_DIRECTORY_PATHS as $path) {
            $optional = $this->run([
                'sh',
                '-lc',
                'if [ -d '
                    .escapeshellarg($path)
                    .' ]; then exec sudo setfacl -m u:agent:--x '
                    .escapeshellarg($path)
                    .'; fi',
            ], timeout: 30);

            if (! $optional->isSuccessful()) {
                throw new RuntimeException(
                    'Could not ensure Orbit agent runtime ACLs (stage=optional_directory_acl).',
                );
            }

            if ($optional->getExitCode() === 0 && is_dir($path)) {
                $optionalDirectoryPaths[] = $path;
            }
        }

        $configAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:r--',
            ...self::REQUIRED_CONFIG_PATHS,
        ], timeout: 30);

        if (! $configAcl->isSuccessful()) {
            throw new RuntimeException(
                'Could not ensure Orbit agent runtime ACLs (stage=config_acl).',
            );
        }

        $binaryAcl = $this->run([
            'sudo',
            'setfacl',
            '-m',
            'u:agent:r-x',
            self::REQUIRED_BINARY_PATH,
        ], timeout: 30);

        if (! $binaryAcl->isSuccessful()) {
            throw new RuntimeException(
                'Could not ensure Orbit agent runtime ACLs (stage=binary_acl).',
            );
        }

        $agentBinaryAcl = $this->run([
            'sh',
            '-lc',
            'if [ -e '
                .escapeshellarg(self::OPTIONAL_AGENT_BINARY_PATH)
                .' ]; then exec sudo setfacl -m u:agent:r-x '
                .escapeshellarg(self::OPTIONAL_AGENT_BINARY_PATH)
                .'; fi',
        ], timeout: 30);

        if (! $agentBinaryAcl->isSuccessful()) {
            throw new RuntimeException(
                'Could not ensure Orbit agent runtime ACLs (stage=agent_binary_acl).',
            );
        }

        return [
            'installed_acl' => $installedAcl,
            'directory_acl_exit_code' => $directoryAcl->getExitCode(),
            'config_acl_exit_code' => $configAcl->getExitCode(),
            'binary_acl_exit_code' => $binaryAcl->getExitCode(),
            'agent_binary_acl_exit_code' => $agentBinaryAcl->getExitCode(),
            'optional_directory_paths' => $optionalDirectoryPaths,
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
