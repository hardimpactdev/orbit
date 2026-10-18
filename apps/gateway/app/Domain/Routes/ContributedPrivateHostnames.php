<?php

declare(strict_types=1);

namespace App\Domain\Routes;

/** Private hostnames an extension reserves, including while it is disabled. */
interface ContributedPrivateHostnames
{
    /** @return array<string, string|null> Each hostname and its address to publish now, or null to reserve it only. */
    public function hostnames(): array;
}
