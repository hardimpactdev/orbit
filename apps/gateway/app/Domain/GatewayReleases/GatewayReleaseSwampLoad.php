<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Models\GatewayRelease;

/** Native publication before the Gateway switch, inside the caller's release lock. */
interface GatewayReleaseSwampLoad
{
    /** @return array<string,mixed> */
    public function load(string $sha, ?GatewayRelease $record): array;
}
