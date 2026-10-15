<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

use App\Domain\Shared\ResourceOperationException;

/** Checks the dependencies between extensions ([Extensions](/reference/extensions#enable-and-disable)). */
final readonly class ExtensionDependencies
{
    public function __construct(private ExtensionRegistry $registry, private ExtensionStore $store) {}

    /** Refuses while an enabled extension depends on the named one. */
    public function assertNotRequired(string $name): void
    {
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
    }
}
