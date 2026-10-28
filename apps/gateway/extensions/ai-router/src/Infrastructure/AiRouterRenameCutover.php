<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Infrastructure;

use App\Actions\Processes\DestroyProcessAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use RuntimeException;

/** One-time removal inputs, never runtime aliases. Removed after live cutover in ADR 0212 step 4. */
final readonly class AiRouterRenameCutover
{
    public function assertComplete(): void
    {
        if ($this->pending()) {
            throw new ResourceOperationException('ai_router.cutover_pending', 'The release cutover is pending. The next extension tick will retry it.', 409);
        }
    }

    public function pending(?int $nodeId = null): bool
    {
        $query = DB::table('ai_router_cutovers');

        return ($nodeId === null ? $query : $query->where('node_id', $nodeId))->exists();
    }

}
