<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Services\OrbitConfigStore;
use Orbit\Core\Extensions\OrbitExtensionRegistry;

final readonly class LocalExtensionState
{
    public function __construct(
        private OrbitConfigStore $configStore,
        private OrbitExtensionRegistry $registry,
    ) {}

    public function enabled(string $slug): bool
    {
        $this->registry->require($slug);

        if ($this->showAllExtensionCommands()) {
            return true;
        }

        return $this->configStore->isExtensionEnabled($slug);
    }

    public function enable(string $slug): void
    {
        $this->registry->require($slug);
        $this->configStore->enableExtension($slug);
    }

    public function disable(string $slug): bool
    {
        $this->registry->require($slug);

        return $this->configStore->disableExtension($slug);
    }

    /**
     * @return list<string>
     */
    public function enabledSlugs(): array
    {
        if ($this->showAllExtensionCommands()) {
            return $this->registry->slugs();
        }

        $enabled = [];

        foreach ($this->registry->slugs() as $slug) {
            if (! $this->enabled($slug)) {
                continue;
            }

            $enabled[] = $slug;
        }

        return $enabled;
    }

    private function showAllExtensionCommands(): bool
    {
        $value = getenv('ORBIT_CLI_SHOW_ALL_EXTENSION_COMMANDS');

        return $value === '1' || $value === 'true';
    }
}
