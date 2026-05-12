<?php

declare(strict_types=1);

namespace App\Contracts;

interface ToolDefinition
{
    public function slug(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    public function installScript(array $config = []): ?string;

    public function removeScript(array $config = []): ?string;

    public function updateScript(array $config = []): ?string;

    public function credentialsScript(array $config = []): ?string;

    public function reconfigureScript(array $config = []): ?string;

    public function latestSupportedVersion(): ?string;

    /**
     * @return array{
     *     binary: string,
     *     version_command?: string,
     *     service?: string,
     *     update_command?: string,
     *     repair_commands?: array<string, string>,
     * }
     */
    public function probeMetadata(): array;
}
