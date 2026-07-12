<?php

declare(strict_types=1);

namespace App\Tools;

use App\Contracts\ToolDefinition;

abstract class BaseTool implements ToolDefinition
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = [];

    protected const ?string REQUIRED_CONTAINER_PROVIDER = null;

    protected const ?string RUNTIME_USER = null;

    protected const bool REQUIRES_ROUTE_TLD = false;

    protected const ?string ISOLATION = null;

    protected const bool GATEWAY_LOCAL = false;

    public function bootstrapRole(): ?string
    {
        return null;
    }

    public function category(): string
    {
        return 'general';
    }

    /**
     * @return list<string>
     */
    public function supportedOperatingSystems(): array
    {
        return static::SUPPORTED_OPERATING_SYSTEMS;
    }

    public function requiredContainerProvider(): ?string
    {
        return static::REQUIRED_CONTAINER_PROVIDER;
    }

    public function runtimeUser(): ?string
    {
        return static::RUNTIME_USER;
    }

    public function requiresRouteTld(): bool
    {
        return static::REQUIRES_ROUTE_TLD;
    }

    public function isolation(): ?string
    {
        return static::ISOLATION;
    }

    public function gatewayLocal(): bool
    {
        return static::GATEWAY_LOCAL;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return [];
    }

    public function installScript(array $config = []): ?string
    {
        return null;
    }

    public function removeScript(array $config = []): ?string
    {
        return null;
    }

    public function updateScript(array $config = []): ?string
    {
        return null;
    }

    public function startScript(array $config = []): ?string
    {
        return $this->lifecycleScript('start', $config);
    }

    public function stopScript(array $config = []): ?string
    {
        return $this->lifecycleScript('stop', $config);
    }

    public function restartScript(array $config = []): ?string
    {
        return $this->lifecycleScript('restart', $config);
    }

    public function reloadScript(array $config = []): ?string
    {
        return $this->lifecycleScript('reload', $config);
    }

    public function credentialsScript(array $config = []): ?string
    {
        return null;
    }

    public function reconfigureScript(array $config = []): ?string
    {
        return null;
    }

    public function latestSupportedVersion(): ?string
    {
        return null;
    }

    /**
     * @return array{name: string, command: string, runtime: string, tool: string}|null
     */
    public function relatedProcess(): ?array
    {
        return null;
    }

    public function probeMetadata(): array
    {
        return ['binary' => $this->slug()];
    }

    protected function dockerComposeInstallScript(string $service, array $config): string
    {
        $composePath = $config['compose_path'] ?? '/opt/orbit/docker-compose.yml';

        return "docker compose -f '{$composePath}' pull '{$service}' && docker compose -f '{$composePath}' up -d '{$service}'";
    }

    protected function dockerComposeRemoveScript(string $service, array $config): string
    {
        $composePath = $config['compose_path'] ?? '/opt/orbit/docker-compose.yml';

        return "docker compose -f '{$composePath}' stop '{$service}' && docker compose -f '{$composePath}' rm -f '{$service}'";
    }

    protected function lifecycleScript(string $action, array $config): ?string
    {
        return null;
    }

    protected function aptInstallScript(string ...$packages): string
    {
        $packageList = implode(' ', array_map(escapeshellarg(...), $packages));

        return <<<BASH
            export DEBIAN_FRONTEND=noninteractive
            sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
            sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq {$packageList}
            BASH;
    }

    /**
     * @return array<string, string>
     */
    protected function serviceRepairCommands(string $service, bool $restart = false, ?string $reload = null): array
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
