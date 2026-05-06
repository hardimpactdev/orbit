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
     * @return array{binary: string, version_command?: string}|null
     */
    public function probeMetadata(string $tool): ?array
    {
        if (! $this->supports($tool)) {
            return null;
        }

        return match ($tool) {
            'redis' => ['binary' => 'redis-server', 'version_command' => 'redis-server --version'],
            'php', 'php-cli' => ['binary' => 'php', 'version_command' => 'php -r "echo PHP_VERSION;"'],
            'composer' => ['binary' => 'composer', 'version_command' => 'composer --version'],
            'caddy' => ['binary' => 'caddy', 'version_command' => 'caddy version'],
            'docker' => ['binary' => 'docker', 'version_command' => 'docker --version'],
            'gh' => ['binary' => 'gh', 'version_command' => 'gh --version'],
            'mysql' => ['binary' => 'mysql', 'version_command' => 'mysql --version'],
            'postgres' => ['binary' => 'psql', 'version_command' => 'psql --version'],
            default => ['binary' => $tool],
        };
    }
}
