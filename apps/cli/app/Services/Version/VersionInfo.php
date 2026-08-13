<?php

declare(strict_types=1);

namespace App\Services\Version;

final readonly class VersionInfo
{
    public bool $updateAvailable;

    public function __construct(
        public string $version,
        public ?string $latestVersion,
        public ?string $releasedAt,
        public ?string $installedAt,
        public ?string $releaseSourceVersion,
    ) {
        $this->updateAvailable = $latestVersion !== null && version_compare($latestVersion, $version, operator: '>');
    }

    /**
     * @return array{
     *     version: string,
     *     latest_version: string|null,
     *     update_available: bool,
     *     released_at: string|null,
     *     installed_at: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'latest_version' => $this->latestVersion,
            'update_available' => $this->updateAvailable,
            'released_at' => $this->releasedAt,
            'installed_at' => $this->installedAt,
        ];
    }
}
