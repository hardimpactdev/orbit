<?php

declare(strict_types=1);

namespace App\Tools;

final class DockerTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux', 'macos'];

    public function slug(): string
    {
        return 'docker';
    }

    #[\Override]
    public function category(): string
    {
        return 'always';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['safe-fix', 'safe-adopt'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        $managedUser = is_string($config['managed_user'] ?? null) && trim($config['managed_user']) !== ''
            ? trim($config['managed_user'])
            : 'orbit';
        $managedUserShell = escapeshellarg($managedUser);

        return <<<BASH
            #!/usr/bin/env bash
            # orbit install docker
            set -e

            if [ "\$(uname -s)" != Linux ] || [ ! -r /etc/os-release ]; then
                printf 'Automatic Docker installation requires Ubuntu.\n' >&2
                exit 1
            fi

            . /etc/os-release
            if [ "\${ID:-}" != ubuntu ]; then
                printf 'Automatic Docker installation requires Ubuntu.\n' >&2
                exit 1
            fi

            export DEBIAN_FRONTEND=noninteractive
            sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
            sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq docker.io
            sudo systemctl enable --now docker
            sudo usermod -aG docker {$managedUserShell}
            BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'docker',
            'version_command' => 'docker --version',
            'service' => 'docker',
            'provider_command' => 'docker info',
            'repair_commands' => $this->serviceRepairCommands('docker', restart: true),
        ];
    }
}
