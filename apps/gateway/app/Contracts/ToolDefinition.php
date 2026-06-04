<?php

declare(strict_types=1);

namespace App\Contracts;

interface ToolDefinition
{
    public function slug(): string;

    public function requiredNodeRole(): ?string;

    public function category(): string;

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
     *     binary?: string,
     *     probe?: string,
     *     images?: list<string>,
     *     version_command?: string,
     *     service?: string,
     *     supervisor_program?: string,
     *     supervisor_log?: string,
     *     container?: string,
     *     image?: string,
     *     update_command?: string,
     *     repair_commands?: array<string, string>,
     * }
     */
    public function probeMetadata(): array;
}
