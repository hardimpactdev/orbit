<?php

declare(strict_types=1);

namespace App\Tools;

final class GhTool extends BaseTool
{
    public function slug(): string
    {
        return 'gh';
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
        return <<<'BASH'
#!/usr/bin/env bash
# orbit install gh
set -e

export DEBIAN_FRONTEND=noninteractive

github_cli_keyring=/etc/apt/keyrings/githubcli-archive-keyring.gpg
github_cli_source=/etc/apt/sources.list.d/github-cli.list
github_cli_source_line="deb [arch=$(dpkg --print-architecture) signed-by=${github_cli_keyring}] https://cli.github.com/packages stable main"
github_cli_metadata_needs_refresh=0

gh_package_candidate_available() {
    apt-cache policy gh 2>/dev/null | awk '/Candidate:/ { found=1; exit ($2 == "(none)") } END { if (! found) exit 1 }'
}

download_github_cli_keyring() {
    sudo install -d -m 0755 /etc/apt/keyrings

    keyring="$(mktemp)"

    if command -v curl >/dev/null 2>&1; then
        curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg -o "${keyring}"
    else
        if ! command -v wget >/dev/null 2>&1; then
            sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
            sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq wget
        fi

        wget -nv -O"${keyring}" https://cli.github.com/packages/githubcli-archive-keyring.gpg
    fi

    cat "${keyring}" | sudo tee "${github_cli_keyring}" >/dev/null
    rm -f "${keyring}"
    sudo chmod go+r "${github_cli_keyring}"
}

install_github_cli_source() {
    sudo install -d -m 0755 /etc/apt/sources.list.d
    echo "${github_cli_source_line}" | sudo tee "${github_cli_source}" >/dev/null
}

refresh_github_cli_metadata() {
    sudo apt-get \
        -o DPkg::Lock::Timeout=300 \
        -o Dir::Etc::sourcelist="sources.list.d/github-cli.list" \
        -o Dir::Etc::sourceparts="-" \
        -o APT::Get::List-Cleanup="0" \
        update -qq
}

if [ ! -s "${github_cli_keyring}" ]; then
    download_github_cli_keyring
    github_cli_metadata_needs_refresh=1
fi

if [ ! -s "${github_cli_source}" ] || ! grep -Fxq "${github_cli_source_line}" "${github_cli_source}"; then
    install_github_cli_source
    github_cli_metadata_needs_refresh=1
fi

if [ "${github_cli_metadata_needs_refresh}" = "1" ] || ! gh_package_candidate_available; then
    refresh_github_cli_metadata
fi

if ! sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq gh; then
    refresh_github_cli_metadata
    sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq gh
fi
BASH;
    }

    public function updateScript(array $config = []): string
    {
        return 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get -o DPkg::Lock::Timeout=300 update -qq && sudo apt-get -o DPkg::Lock::Timeout=300 install --only-upgrade -y -qq gh';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'gh',
            'version_command' => 'gh --version',
            'update_command' => $this->updateScript(),
        ];
    }
}
