<?php

declare(strict_types=1);

namespace App\Actions\Extensions;

use App\Domain\Extensions\ExtensionDependencies;
use App\Domain\Extensions\ExtensionRegistry;
use App\Domain\Extensions\ExtensionStore;

/** Turns one extension off while no enabled extension depends on it, then runs its disable hook. */
final readonly class DisableExtensionAction
{
    public function __construct(private ExtensionRegistry $registry, private ExtensionStore $store, private ExtensionDependencies $dependencies) {}

    public function handle(string $name): void
    {
        $extension = $this->registry->find($name);
        $this->dependencies->assertNotRequired($name);

        $this->store->set($name, false);
        $extension->disable();
    }
}
