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

            {$this->upstreamStableAptSourceScript()}
            sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq git
            BASH;
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return <<<BASH
            #!/usr/bin/env bash
            # orbit update git
            set -e

            {$this->upstreamStableAptSourceScript()}
            sudo apt-get -o DPkg::Lock::Timeout=300 install --only-upgrade -y -qq git
            BASH;
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

    private function upstreamStableAptSourceScript(): string
    {
        return <<<'BASH'
            export DEBIAN_FRONTEND=noninteractive

            if ! command -v add-apt-repository >/dev/null 2>&1; then
                sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
                sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq software-properties-common
            fi

            sudo add-apt-repository -y ppa:git-core/ppa
            sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
            BASH;
    }
}
