<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

/**
 * A plug-in point: private DNS host records that an extension contributes ([Extensions](/reference/extensions#plug-in-points)).
 *
 * Each hostname must be one that an extension reserves through ContributedReservedHostnames, so neither a core
 * record nor a Route can answer for it.
 */
interface ContributedPrivateDnsRecords
{
    /**
     * The records to publish now, from stored state.
     *
     * @return array<string, string> Each hostname and the address it answers with.
     */
    public function records(): array;
}
