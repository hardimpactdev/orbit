<?php

declare(strict_types=1);

namespace App\Infrastructure\Releases;

use RuntimeException;

/**
 * A release whose provenance does not verify ([Release provenance](/reference/gateway-recovery#release-provenance)).
 * `retryable` is true only when the bundle could not be downloaded; a bundle that is missing or does not verify
 * stays that way, because a published release never changes.
 */
final class ReleaseProvenanceRefused extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
