<?php

declare(strict_types=1);

namespace App\Tools;

final class GitTool extends BaseTool
{
    public function slug(): string
    {
        return 'git';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'safe-adopt'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return <<<BASH
            #!/usr/bin/env bash
            # orbit install git
            set -e

            {$this->aptInstallScript('git')}
            BASH;
    }

    public function updateScript(array $config = []): string
    {
        return 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get -o DPkg::Lock::Timeout=300 update -qq && sudo apt-get -o DPkg::Lock::Timeout=300 install --only-upgrade -y -qq git';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'git',
            'version_command' => 'git --version',
            'update_command' => $this->updateScript(),
        ];
    }
}
