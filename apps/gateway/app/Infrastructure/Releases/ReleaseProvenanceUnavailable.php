<?php

declare(strict_types=1);

namespace App\Infrastructure\Releases;

use RuntimeException;

/**
 * Provenance that cannot be checked now, which says nothing about the release
 * ([Release provenance](/reference/gateway-recovery#release-provenance)). `local` is true when a Gateway setting is
 * the fault: an unreadable or malformed trust root, or a release repository that is not on github.com. Otherwise
 * the bundle could not be downloaded.
 */
final class ReleaseProvenanceUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly bool $local)
    {
        parent::__construct($message);
    }
}
