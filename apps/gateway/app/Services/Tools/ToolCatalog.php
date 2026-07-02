<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\ToolDefinition;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class ToolCatalog
{
    public function __construct(
        private ToolDefinitionRegistry $definitions,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->definitions->names();
    }

    /**
     * @return list<ToolDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions->all();
    }

    public function definition(string $tool): ?ToolDefinition
    {
        return $this->definitions->get($tool);
    }

    public function supports(string $tool): bool
    {
        return $this->definition($tool) instanceof ToolDefinition;
    }

    public function hasRepairCommand(string $tool, string $key): bool
    {
        return $this->repairCommand($tool, $key) !== null;
    }

    public function repairCommand(string $tool, string $key): ?string
    {
        $metadata = $this->probeMetadata($tool);
        $commands = is_array($metadata['repair_commands'] ?? null)
            ? $metadata['repair_commands']
            : [];
        $command = $commands[$key] ?? null;

        return is_string($command) && $command !== '' ? $command : null;
    }

    public function logCommand(string $tool, int $lines, bool $follow = false): ?string
    {
        $metadata = $this->probeMetadata($tool);
        $container = is_string($metadata['container'] ?? null)
            ? $metadata['container']
            : null;
        $lineCount = max(1, $lines);

        if ($container !== null && $container !== '') {
            return sprintf(
                'docker logs --tail %s%s %s 2>&1',
                escapeshellarg((string) $lineCount),
                $follow ? ' -f' : '',
                escapeshellarg($container),
            );
        }

        $service = is_string($metadata['service'] ?? null)
            ? $metadata['service']
            : null;

        if ($service === null || $service === '') {
            return null;
        }

        if (! $follow) {
            return sprintf(
                'sudo bash -lc %s',
                escapeshellarg(sprintf(
                    'output="$(%s 2>/dev/null | sed "/^-- No entries --$/d")"; if [ -n "$output" ]; then printf "%%s\n" "$output"; else systemctl status %s --no-pager --lines=%d 2>/dev/null || true; fi',
                    $this->journalctlCommand($service, $lineCount),
                    escapeshellarg($service),
                    $lineCount,
                )),
            );
        }

        return sprintf(
            'sudo %s',
            $this->journalctlCommand($service, $lineCount, follow: true),
        );
    }

    private function journalctlCommand(string $service, int $lines, bool $follow = false): string
    {
        $unit = str_contains($service, '.') ? $service : "{$service}.service";

        return sprintf(
            'journalctl _SYSTEMD_UNIT=%s + SYSLOG_IDENTIFIER=%s -n %d%s --no-pager --output=short-iso',
            escapeshellarg($unit),
            escapeshellarg($service),
            $lines,
            $follow ? ' -f' : '',
        );
    }

    /**
     * @return list<string>
     */
    public function capabilities(string $tool): array
    {
        return $this->definition($tool)?->capabilities() ?? [];
    }

    public function hasCapability(string $tool, string $capability): bool
    {
        return in_array($capability, $this->capabilities($tool), true);
    }

    public function requiredNodeRole(string $tool): ?string
    {
        return $this->definition($tool)?->requiredNodeRole();
    }

    public function category(string $tool): ?string
    {
        return $this->definition($tool)?->category();
    }

    /**
     * @return list<string>
     */
    public function supportedOperatingSystems(string $tool): array
    {
        return $this->definition($tool)?->supportedOperatingSystems() ?? [];
    }

    public function supportsNode(string $tool, Node $node): bool
    {
        if (! $node->isActive()) {
            return false;
        }

        if (app(NodeRoleAssignments::class)->nodeIsGateway($node)) {
            return false;
        }

        return $this->supportsPlatform($tool, $node->platform);
    }

    public function supportsPlatform(string $tool, ?string $platform): bool
    {
        $operatingSystem = $this->operatingSystemForPlatform($platform);

        if ($operatingSystem === null) {
            return false;
        }

        return in_array($operatingSystem, $this->supportedOperatingSystems($tool), strict: true);
    }

    public function operatingSystemForPlatform(?string $platform): ?string
    {
        if (! is_string($platform) || trim($platform) === '') {
            return 'linux';
        }

        $family = strtolower(explode('_', trim($platform), 2)[0]);

        return match ($family) {
            'darwin', 'macos' => 'macos',
            'debian', 'linux', 'ubuntu' => 'linux',
            default => null,
        };
    }

    /**
     * @return array{name: string, command: string, runtime: string, tool: string}|null
     */
    public function relatedProcess(string $tool): ?array
    {
        return $this->definition($tool)?->relatedProcess();
    }

    public function installScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'install')) {
            return null;
        }

        return $this->definition($tool)?->installScript($config);
    }

    public function removeScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'remove')) {
            return null;
        }

        return $this->definition($tool)?->removeScript($config);
    }

    public function updateScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'update')) {
            return null;
        }

        return $this->definition($tool)?->updateScript($config);
    }

    public function latestSupportedVersion(string $tool): ?string
    {
        return $this->definition($tool)?->latestSupportedVersion();
    }

    public function credentialsScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'credentials')) {
            return null;
        }

        return $this->definition($tool)?->credentialsScript($config);
    }

    public function reconfigureScript(string $tool, array $config = []): ?string
    {
        if (! $this->hasCapability($tool, 'reconfigure')) {
            return null;
        }

        return $this->definition($tool)?->reconfigureScript($config);
    }

    /**
     * @return array{
     *     binary?: string,
     *     probe?: string,
     *     images?: list<string>,
     *     version_command?: string,
     *     service?: string,
     *     container?: string,
     *     image?: string,
     *     provider_command?: string,
     *     update_command?: string,
     *     repair_commands?: array<string, string>,
     * }|null
     */
    public function probeMetadata(string $tool): ?array
    {
        return $this->definition($tool)?->probeMetadata();
    }
}
