<?php

declare(strict_types=1);

namespace App\Actions\Extensions;

use App\Domain\Extensions\ExtensionRegistry;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Shared\ResourceOperationException;

/** Turns one extension off while no enabled extension depends on it, then runs its disable hook. */
final readonly class DisableExtensionAction
{
    public function __construct(private ExtensionRegistry $registry, private ExtensionStore $store) {}

    public function handle(string $name): void
    {
        $extension = $this->registry->find($name);
        foreach ($this->registry->all() as $dependent) {
            if (in_array($name, $dependent->dependencies(), true) && $this->store->enabled($dependent->name())) {
                throw new ResourceOperationException(
                    errorCode: 'extension.required',
                    message: "The {$dependent->name()} extension needs the {$name} extension. Disable {$dependent->name()} first.",
                    status: 409,
                    details: ['extension' => $name, 'required_by' => $dependent->name()],
                );
            }
        }

        $this->store->set($name, false);
        $extension->disable();
    }
}
