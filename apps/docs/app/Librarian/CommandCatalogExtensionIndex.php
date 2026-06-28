<?php

declare(strict_types=1);

namespace App\Librarian;

use Orbit\Core\Extensions\OrbitExtensionRegistry;

final readonly class CommandCatalogExtensionIndex
{
    public function __construct(
        private OrbitExtensionRegistry $registry,
    ) {}

    /**
     * @return array{slug: string, label: string, description: string}|null
     */
    public function forCommand(CliCommand $command): ?array
    {
        $definition = $this->registry->extensionForCommand($command->name);

        if ($definition === null) {
            return null;
        }

        return [
            'slug' => $definition->slug,
            'label' => $definition->label,
            'description' => $definition->description,
        ];
    }
}
