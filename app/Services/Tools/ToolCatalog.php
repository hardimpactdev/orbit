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
