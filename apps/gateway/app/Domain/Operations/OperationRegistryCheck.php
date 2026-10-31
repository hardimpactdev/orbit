<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use Illuminate\Contracts\Container\Container;
use LogicException;

/** Resolves native declaration wiring without executing an Operation. */
final readonly class OperationRegistryCheck
{
    public function __construct(private Container $container, private DirectOperation $direct) {}

    /** @param array<string,array<string,mixed>>|null $definitions */
    public function execute(?array $definitions = null): int
    {
        $definitions ??= $this->direct->definitions();
        foreach ($definitions as $name => $definition) {
            if (! $this->container->make($definition['projection']) instanceof DirectOperationProjection) {
                throw new LogicException("Operation [{$name}] needs a native result projection.");
            }
        }

        return count($definitions);
    }
}
