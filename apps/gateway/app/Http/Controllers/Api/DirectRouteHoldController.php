<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Instances\InstanceDestroyRouteWithdrawal;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\DirectRouteHoldRequest;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** The two calls of a run around its Route-publish step, and a removal's release after its withdraw step (docs/reference/routes.md#coordinate-publication). */
final class DirectRouteHoldController extends Controller
{
    /** The Gateway answers after this long when the Nodes stay busy; the step asks again. */
    private const int WaitSeconds = 5;

    private static function waitSeconds(): int
    {
        $seconds = config('operations.route_hold_wait_seconds', self::WaitSeconds);

        return is_int($seconds) ? max(0, $seconds) : self::WaitSeconds;
    }

    public function hold(DirectRouteHoldRequest $request, Operation $operation, RoutePublicationHolds $holds): JsonResponse
    {
        if ($operation->status->terminal()) {
            throw new ResourceOperationException('operation.finished', 'The Operation has ended.', 409);
        }
        $nodes = $holds->holdStep($operation, self::waitSeconds());
        if ($nodes === null) {
            throw new ResourceOperationException('route_hold.busy', 'Another Route write holds a Node of this step.', 423);
        }

        return response()->json(['data' => ['held' => $nodes], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function release(DirectRouteHoldRequest $request, Operation $operation, RoutePublicationHolds $holds): JsonResponse
    {
        if (RoutePublicationHolds::releasesAfterWithdraw($operation->kind)) {
            // A removal's Route is withdrawn: a retry of it has no Route work left, and its Nodes serve the next write now.
            DB::transaction(static function () use ($operation, $holds): void {
                app(InstanceDestroyRouteWithdrawal::class)->record($operation);
                $holds->releaseWithdrawn($operation);
            });
        } else {
            $holds->releaseStep($operation);
        }

        return response()->json(['data' => ['released' => true], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
