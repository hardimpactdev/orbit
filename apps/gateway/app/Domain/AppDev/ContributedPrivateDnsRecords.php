<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

/**
 * A plug-in point: private DNS host records that an extension contributes ([Extensions](/reference/extensions#plug-in-points)).
 *
 * Each hostname must be one that an extension reserves through ContributedReservedHostnames. It cannot take a core
 * name, and new Routes cannot take it. Existing Routes remain published; Doctor reports conflicts for an operator
 * to resolve by changing or removing the Route.
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
