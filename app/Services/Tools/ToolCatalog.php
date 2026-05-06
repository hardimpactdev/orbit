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

    /**
     * @return array{
     *     binary: string,
     *     version_command?: string,
     *     service?: string,
     *     repair_commands?: array<string, string>,
     * }|null
     */
    public function probeMetadata(string $tool): ?array
    {
        if (! $this->supports($tool)) {
            return null;
        }

        return match ($tool) {
            'redis' => ['binary' => 'redis-server', 'version_command' => 'redis-server --version', 'service' => 'redis-server', 'repair_commands' => $this->serviceRepairCommands('redis-server')],
            'php', 'php-cli' => ['binary' => 'php', 'version_command' => 'php -r "echo PHP_VERSION;"'],
            'composer' => ['binary' => 'composer', 'version_command' => 'composer --version'],
            'caddy' => ['binary' => 'caddy', 'version_command' => 'caddy version', 'service' => 'caddy', 'repair_commands' => $this->serviceRepairCommands('caddy')],
            'supervisor' => ['binary' => 'supervisord', 'service' => 'supervisor', 'repair_commands' => $this->serviceRepairCommands('supervisor')],
            'docker' => ['binary' => 'docker', 'version_command' => 'docker --version', 'service' => 'docker', 'repair_commands' => $this->serviceRepairCommands('docker')],
            'gh' => ['binary' => 'gh', 'version_command' => 'gh --version'],
            'mysql' => ['binary' => 'mysql', 'version_command' => 'mysql --version'],
            'postgres' => ['binary' => 'psql', 'version_command' => 'psql --version'],
            default => ['binary' => $tool],
        };
    }

    /**
     * @return array<string, string>
     */
    private function serviceRepairCommands(string $service): array
    {
        return [
            'lifecycle_running' => "sudo systemctl start {$service}",
            'lifecycle_stopped' => "sudo systemctl stop {$service}",
        ];
    }
}
