<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * The Vite+ of the runtime folder (docs/reference/compute-drivers.md#shared-instance-folders). `vp` runs only from a home that
 * holds its locked `vite-plus` package and its Node, and a container has no internet to fetch them. So the host builds that home
 * once, from the recipe's `vp-cli` part, its `vp` runtime lock and its Node release, and containers use it read-only.
 */
final readonly class VitePlusHome
{
    /**
     * @param  string  $part  The runtime part that holds the `vp-cli` release.
     * @param  string  $binary  The path of `vp` inside that part.
     */
    public function __construct(
        public string $part,
        public string $binary,
        public ToolPin $vp,
        public string $node,
    ) {}
}
