<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

/**
 * The one admission boundary for container Git observations; no observer supplies them yet.
 * Future observations are dirty when uncommitted > 0, unpushed > 0, or has_upstream is false.
 * Missing, stale (over ten minutes), or offline observations remain unknown, never safe.
 */
final readonly class ConnInstanceWorkState
{
    public function assertRemovalSafe(Instance $instance): never
    {
        throw new ResourceOperationException('instance.unsaved_work',
            'The Instance may hold unsaved work. Acknowledge its removal to continue.', 409,
            details: ['uncommitted' => null, 'unpushed' => null, 'state' => 'unknown']);
    }
}
