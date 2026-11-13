<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Models\Instance;

/**
 * The shared read boundary for container Git observations; no observation store exists yet.
 * Later agent observations supply branch, uncommitted, unpushed, has_upstream and read_at.
 * Observations over ten minutes old or from an offline agent stay unknown/not_observed.
 * Future removal policy belongs here: dirty when uncommitted > 0, unpushed > 0, or no upstream.
 * Until those observations exist, removal state stays unknown. This change does not wire removal.
 */
final readonly class ConnInstanceGitState
{
    /** @return array{state:'unknown',reason:'not_observed'} */
    public function forInstance(Instance $instance): array
    {
        return ['state' => 'unknown', 'reason' => 'not_observed'];
    }
}
