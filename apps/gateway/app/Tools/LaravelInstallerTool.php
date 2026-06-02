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
        return <<<'BASH'
#!/usr/bin/env bash
# orbit install laravel-installer
set -e

COMPOSER_HOME="/home/orbit/.config/composer"

# Install the Laravel installer globally as the orbit system user
sudo -u orbit -H bash -lc "COMPOSER_HOME=${COMPOSER_HOME} composer global require laravel/installer --no-interaction --no-progress"

# Symlink the laravel binary into /usr/local/bin so it is on PATH system-wide
if [ ! -f /usr/local/bin/laravel ]; then
    sudo ln -sf "${COMPOSER_HOME}/vendor/bin/laravel" /usr/local/bin/laravel
fi
BASH;
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return <<<'BASH'
#!/usr/bin/env bash
# orbit update laravel-installer
set -e

COMPOSER_HOME="/home/orbit/.config/composer"

sudo -u orbit -H bash -lc "COMPOSER_HOME=${COMPOSER_HOME} composer global update laravel/installer --no-interaction --no-progress"
BASH;
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
}
