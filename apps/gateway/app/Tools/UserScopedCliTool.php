<?php

declare(strict_types=1);

namespace App\Tools;

abstract class UserScopedCliTool extends BaseTool
{
    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux', 'macos'];

    #[\Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'update', 'safe-adopt'];
    }

    public function supportsInstallVersion(): bool
    {
        return false;
    }

    #[\Override]
    public function installScript(array $config = []): string
    {
        return UserScopedCliShell::installScript($this, $config);
    }

    #[\Override]
    public function updateScript(array $config = []): string
    {
        return UserScopedCliShell::updateScript($this, $config);
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return $this->probeMetadataForUser('orbit');
    }

    /**
     * @return array{binary: string, version_command: string}
     */
    public function probeMetadataForUser(string $user): array
    {
        return UserScopedCliShell::probeMetadataForUser($this, $user);
    }

    abstract public function cliProfile(): UserScopedCliProfile;
}
