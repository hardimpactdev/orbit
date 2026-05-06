<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolCatalog
{
    private const array SUPPORTED = [
        'caddy',
        'supervisor',
        'docker',
        'viteplus',
        'php-cli',
        'gh',
        'composer',
        'dns',
        'php',
        'postgres',
        'mysql',
        'redis',
        'mailpit',
        'reverb',
        'polyscope-server',
        'opencode-server',
    ];

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return self::SUPPORTED;
    }

    public function supports(string $tool): bool
    {
        return in_array($tool, self::SUPPORTED, true);
    }

    public function hasRepairCommand(string $tool, string $key): bool
    {
        return $this->repairCommand($tool, $key) !== null;
    }

    public function repairCommand(string $tool, string $key): ?string
    {
        $metadata = $this->probeMetadata($tool);
        $commands = is_array($metadata) && is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];
        $command = $commands[$key] ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }

    public function logCommand(string $tool, int $lines, bool $follow = false): ?string
    {
        $metadata = $this->probeMetadata($tool);
        $service = is_array($metadata) && is_string($metadata['service'] ?? null)
            ? $metadata['service']
            : null;

        if ($service === null || $service === '') {
            return null;
        }

        $lineCount = max(1, $lines);

        return sprintf(
            'sudo journalctl -u %s -n %d%s --no-pager --output=short-iso',
            escapeshellarg($service),
            $lineCount,
            $follow ? ' -f' : '',
        );
    }

    /**
     * @return list<string>
     */
    public function capabilities(string $tool): array
    {
        if (! $this->supports($tool)) {
            return [];
        }

        return match ($tool) {
            'redis', 'mailpit', 'reverb', 'postgres', 'mysql' => [
                'install', 'remove', 'start', 'stop', 'restart', 'update', 'logs', 'credentials', 'safe-fix', 'safe-adopt',
            ],
            'php' => ['install', 'remove', 'update'],
            'polyscope-server', 'opencode-server' => ['install', 'remove', 'start', 'stop', 'restart', 'update', 'safe-fix'],
            default => [],
        };
    }

    public function hasCapability(string $tool, string $capability): bool
    {
        return in_array($capability, $this->capabilities($tool), true);
    }

    public function installScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'install')) {
            return null;
        }

        return match ($tool) {
            'redis', 'mailpit', 'reverb', 'postgres', 'mysql' => $this->dockerComposeInstallScript($tool, $config),
            default => null,
        };
    }

    public function removeScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'remove')) {
            return null;
        }

        return match ($tool) {
            'redis', 'mailpit', 'reverb', 'postgres', 'mysql' => $this->dockerComposeRemoveScript($tool, $config),
            default => null,
        };
    }

    public function updateScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'update')) {
            return null;
        }

        return match ($tool) {
            'redis', 'mailpit', 'reverb', 'postgres', 'mysql' => $this->dockerComposeInstallScript($tool, $config),
            'composer', 'caddy', 'gh' => $this->probeMetadata($tool)['update_command'] ?? null,
            default => null,
        };
    }

    private function dockerComposeInstallScript(string $service, array $config): string
    {
        $composePath = $config['compose_path'] ?? '/opt/orbit/docker-compose.yml';

        return "docker compose -f '{$composePath}' pull '{$service}' && docker compose -f '{$composePath}' up -d '{$service}'";
    }

    private function dockerComposeRemoveScript(string $service, array $config): string
    {
        $composePath = $config['compose_path'] ?? '/opt/orbit/docker-compose.yml';

        return "docker compose -f '{$composePath}' stop '{$service}' && docker compose -f '{$composePath}' rm -f '{$service}'";
    }

    /**
     * @return array{
     *     binary: string,
     *     version_command?: string,
     *     service?: string,
     *     update_command?: string,
     *     repair_commands?: array<string, string>,
     * }|null
     */
    public function probeMetadata(string $tool): ?array
    {
        if (! $this->supports($tool)) {
            return null;
        }

        return match ($tool) {
            'redis' => ['binary' => 'redis-server', 'version_command' => 'redis-server --version', 'service' => 'redis-server', 'repair_commands' => $this->serviceRepairCommands('redis-server', restart: true)],
            'php', 'php-cli' => ['binary' => 'php', 'version_command' => 'php -r "echo PHP_VERSION;"'],
            'composer' => ['binary' => 'composer', 'version_command' => 'composer --version', 'update_command' => 'sudo composer self-update 2>/dev/null'],
            'caddy' => ['binary' => 'caddy', 'version_command' => 'caddy version', 'service' => 'caddy', 'update_command' => 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get update -qq && sudo apt-get install --only-upgrade -y caddy 2>/dev/null', 'repair_commands' => $this->serviceRepairCommands('caddy', restart: true, reload: 'sudo caddy reload --config /etc/caddy/Caddyfile')],
            'supervisor' => ['binary' => 'supervisord', 'service' => 'supervisor', 'repair_commands' => $this->serviceRepairCommands('supervisor', reload: 'sudo supervisorctl reread')],
            'docker' => ['binary' => 'docker', 'version_command' => 'docker --version', 'service' => 'docker', 'repair_commands' => $this->serviceRepairCommands('docker', restart: true)],
            'gh' => ['binary' => 'gh', 'version_command' => 'gh --version', 'update_command' => 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get install --only-upgrade -y gh 2>/dev/null'],
            'mysql' => ['binary' => 'mysql', 'version_command' => 'mysql --version'],
            'postgres' => ['binary' => 'psql', 'version_command' => 'psql --version'],
            default => ['binary' => $tool],
        };
    }

    /**
     * @return array<string, string>
     */
    private function serviceRepairCommands(string $service, bool $restart = false, ?string $reload = null): array
    {
        $commands = [
            'lifecycle_running' => "sudo systemctl start {$service}",
            'lifecycle_stopped' => "sudo systemctl stop {$service}",
        ];

        if ($restart) {
            $commands['lifecycle_restarted'] = "sudo systemctl restart {$service}";
        }

        if ($reload !== null) {
            $commands['lifecycle_reloaded'] = $reload;
        }

        return $commands;
    }
}
