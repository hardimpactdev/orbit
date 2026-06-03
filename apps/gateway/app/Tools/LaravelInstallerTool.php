<?php

declare(strict_types=1);

namespace App\Tools;

final class LaravelInstallerTool extends BaseTool
{
    public function slug(): string
    {
        return 'laravel-installer';
    }

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'remove'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return str_replace('__GITHUB_TOKEN_FILE__', $this->githubTokenFile($config), <<<'BASH'
#!/usr/bin/env bash
# orbit install laravel-installer
set -e

COMPOSER_HOME="/home/orbit/.config/composer"
GITHUB_TOKEN_FILE=__GITHUB_TOKEN_FILE__

configure_github_auth() {
    if [ -z "\${GITHUB_TOKEN_FILE}" ] || [ ! -f "\${GITHUB_TOKEN_FILE}" ]; then
        return
    fi

    sudo install -d -m 700 -o orbit -g orbit "\${COMPOSER_HOME}"

    auth_file="\$(mktemp)"
    php -r 'echo json_encode(["github-oauth" => ["github.com" => trim(file_get_contents($argv[1]))]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);' "\${GITHUB_TOKEN_FILE}" > "\${auth_file}"
    sudo install -m 600 -o orbit -g orbit "\${auth_file}" "\${COMPOSER_HOME}/auth.json"
    rm -f "\${auth_file}"

    if command -v gh >/dev/null 2>&1; then
        sudo -u orbit -H bash -lc 'cat "$1" | gh auth login --hostname github.com --with-token >/dev/null 2>&1 || true; gh auth setup-git --hostname github.com >/dev/null 2>&1 || true' bash "\${GITHUB_TOKEN_FILE}"
    fi
}

configure_github_auth

# Install the Laravel installer globally as the orbit system user
sudo -u orbit -H bash -lc "COMPOSER_HOME=${COMPOSER_HOME} composer global require laravel/installer --no-interaction --no-progress"

# Symlink the laravel binary into /usr/local/bin so it is on PATH system-wide
if [ ! -f /usr/local/bin/laravel ]; then
    sudo ln -sf "${COMPOSER_HOME}/vendor/bin/laravel" /usr/local/bin/laravel
fi
BASH
        );
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return str_replace('__GITHUB_TOKEN_FILE__', $this->githubTokenFile($config), <<<'BASH'
#!/usr/bin/env bash
# orbit update laravel-installer
set -e

COMPOSER_HOME="/home/orbit/.config/composer"
GITHUB_TOKEN_FILE=__GITHUB_TOKEN_FILE__

configure_github_auth() {
    if [ -z "\${GITHUB_TOKEN_FILE}" ] || [ ! -f "\${GITHUB_TOKEN_FILE}" ]; then
        return
    fi

    sudo install -d -m 700 -o orbit -g orbit "\${COMPOSER_HOME}"

    auth_file="\$(mktemp)"
    php -r 'echo json_encode(["github-oauth" => ["github.com" => trim(file_get_contents($argv[1]))]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);' "\${GITHUB_TOKEN_FILE}" > "\${auth_file}"
    sudo install -m 600 -o orbit -g orbit "\${auth_file}" "\${COMPOSER_HOME}/auth.json"
    rm -f "\${auth_file}"

    if command -v gh >/dev/null 2>&1; then
        sudo -u orbit -H bash -lc 'cat "$1" | gh auth login --hostname github.com --with-token >/dev/null 2>&1 || true; gh auth setup-git --hostname github.com >/dev/null 2>&1 || true' bash "\${GITHUB_TOKEN_FILE}"
    fi
}

configure_github_auth

sudo -u orbit -H bash -lc "COMPOSER_HOME=${COMPOSER_HOME} composer global update laravel/installer --no-interaction --no-progress"
BASH
        );
    }

    #[\Override]
    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit remove laravel-installer
set -e

COMPOSER_HOME="/home/orbit/.config/composer"

sudo -u orbit -H bash -lc "COMPOSER_HOME=${COMPOSER_HOME} composer global remove laravel/installer --no-interaction 2>/dev/null || true"

sudo rm -f /usr/local/bin/laravel
BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'laravel',
            'version_command' => 'laravel --version 2>/dev/null || true',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function githubTokenFile(array $config): string
    {
        $path = $config['github_token_file'] ?? '';

        return escapeshellarg(is_string($path) ? $path : '');
    }
}
