<?php

declare(strict_types=1);

namespace App\Tools;

final class CaddyTool extends BaseTool
{
    public function slug(): string
    {
        return 'caddy';
    }

    #[\Override]
    public function category(): string
    {
        return 'always';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['start', 'stop', 'restart', 'reload', 'reconfigure', 'update', 'logs', 'safe-fix', 'safe-adopt'];
    }

    public function reconfigureScript(array $config = []): string
    {
        return "#!/usr/bin/env bash\n# orbit reconfigure caddy\nsudo caddy reload --config /etc/caddy/Caddyfile";
    }

    public function updateScript(array $config = []): string
    {
        return 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get update -qq && sudo apt-get install --only-upgrade -y caddy 2>/dev/null';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'caddy',
            'version_command' => 'caddy version',
            'service' => 'caddy',
            'update_command' => $this->updateScript(),
            'repair_commands' => $this->serviceRepairCommands(
                service: 'caddy',
                restart: true,
                reload: 'sudo caddy reload --config /etc/caddy/Caddyfile',
            ),
        ];
    }
}
