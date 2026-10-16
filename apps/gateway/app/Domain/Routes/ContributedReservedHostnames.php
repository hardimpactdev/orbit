<?php

declare(strict_types=1);

namespace App\Domain\Routes;

/**
 * A plug-in point: private hostnames that an extension reserves, so no Route can own them
 * ([Extensions](/reference/extensions#plug-in-points)). A hostname that is already reserved is a wiring error.
 */
interface ContributedReservedHostnames
{
    /** @return list<string> */
    public function hostnames(): array;
}
