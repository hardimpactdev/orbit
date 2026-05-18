<?php

declare(strict_types=1);

namespace App\Tools;

final class Sqlite3Tool extends BaseTool
{
    public function slug(): string
    {
        return 'sqlite3';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'safe-fix', 'safe-adopt'];
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return implode("\n", [
            'set -e',
            $this->aptInstallScript('sqlite3'),
        ]);
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return $this->installScript($config);
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'sqlite3',
            'version_command' => 'sqlite3 --version',
            'update_command' => $this->updateScript(),
        ];
    }
}
