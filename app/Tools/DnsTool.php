<?php

declare(strict_types=1);

namespace App\Tools;

final class DnsTool extends BaseTool
{
    public function slug(): string
    {
        return 'dns';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['start', 'stop', 'restart', 'update', 'logs', 'safe-fix', 'safe-adopt'];
    }

    public function installScript(array $config = []): string
    {
        return $this->dockerComposeInstallScript($this->service($config), $config);
    }

    public function removeScript(array $config = []): string
    {
        return $this->dockerComposeRemoveScript($this->service($config), $config);
    }

    public function updateScript(array $config = []): string
    {
        return $this->installScript($config);
    }

    private function service(array $config): string
    {
        return (string) ($config['service'] ?? 'dns');
    }
}
