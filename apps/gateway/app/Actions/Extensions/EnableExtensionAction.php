<?php

declare(strict_types=1);

namespace App\Actions\Extensions;

use App\Domain\Extensions\ExtensionRegistry;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Shared\ResourceOperationException;

/**
 * Runs one extension's enable hook after its dependencies, then turns its switch on. The switch is written only after
 * the hook returns, so a hook that throws leaves the switch as it was and its error reaches the caller.
 */
final readonly class EnableExtensionAction
{
    public function __construct(private ExtensionRegistry $registry, private ExtensionStore $store) {}

    public function handle(string $name): void
    {
        $extension = $this->registry->find($name);
        foreach ($extension->dependencies() as $dependency) {
            if (! in_array($dependency, $this->registry->names(), true) || ! $this->store->enabled($dependency)) {
                throw new ResourceOperationException(
                    errorCode: 'extension.dependency_disabled',
                    message: "The {$name} extension needs the {$dependency} extension. Enable {$dependency} first.",
                    status: 409,
                    details: ['extension' => $name, 'dependency' => $dependency],
                );
            }
        }

        $extension->enable();
        $this->store->set($name, true);
    }
}
