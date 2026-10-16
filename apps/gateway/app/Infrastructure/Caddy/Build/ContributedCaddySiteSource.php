<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

/**
 * A plug-in point: a site source that an extension contributes ([Extensions](/reference/extensions#plug-in-points)).
 *
 * The build renders it after core's sources. It may use neither a core source name nor a core certificate slug, so
 * it can neither pass the task VM guard of a core site nor remove a core certificate record.
 */
interface ContributedCaddySiteSource extends NodeCaddySiteSource
{
    /** The slug of its certificate record in CaddySiteCertificates, or null when its sites use no Orbit CA certificate. */
    public function certificateSlug(): ?string;
}
