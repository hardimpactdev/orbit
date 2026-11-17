<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Proof\ProofWarmPool;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** proof:pool:drain and proof:pool:resume (docs/cli/proof/pool-drain.mdx): record-only changes of the warm proof copy pool. */
final class ProofPoolController extends Controller
{
    /** Records the drain and destroys the idle warm copies; running proofs keep theirs. */
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function drain(ProofWarmPool $pool): JsonResponse
    {
        $pool->drain();

        return response()->json(['data' => $pool->state()]);
    }

    /** Ends a drain and refills the pool. */
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function resume(ProofWarmPool $pool): JsonResponse
    {
        $pool->resume();

        return response()->json(['data' => $pool->state()]);
    }
}
