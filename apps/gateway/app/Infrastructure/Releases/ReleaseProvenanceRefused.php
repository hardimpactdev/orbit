<?php

declare(strict_types=1);

namespace App\Infrastructure\Releases;

use RuntimeException;

/**
 * A release whose provenance bundle is missing or does not verify
 * ([Release provenance](/reference/gateway-recovery#release-provenance)). It stays that way, because a published
 * release never changes.
 */
final class ReleaseProvenanceRefused extends RuntimeException {}
