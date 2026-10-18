<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Infrastructure\Processes\CommandResult;

final readonly class InstalledToolInventory
{
    /**
     * @param  array<string, list<string>>  $versionsByPackage
     * @param  list<string>  $unverifiablePackages
     */
    public function __construct(
        private array $versionsByPackage,
        private ?CommandResult $result = null,
        private array $unverifiablePackages = [],
    ) {}

    public function versionFor(string $package): ?string
    {
        $versions = $this->versionsByPackage[$package] ?? [];

        if (in_array($package, $this->unverifiablePackages, true)) {
            throw new ToolManagerException('installed-version', 'The installed package version could not be verified.', $this->result);
        }

        if ($versions === []) {
            return null;
        }

        if (count($versions) !== 1) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The installed inventory contains ambiguous package versions.',
                result: $this->result,
            );
        }

        return $versions[0];
    }
}
