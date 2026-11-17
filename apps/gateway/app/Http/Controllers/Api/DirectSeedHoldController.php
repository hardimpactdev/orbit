<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\DirectSeedHoldRequest;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;

/** The call of a proof default's deployment before its first `seed` phase (docs/reference/development-containers.md#the-seed-hold). */
final class DirectSeedHoldController extends Controller
{
    /** The Gateway answers after this long while a seed copy holds the seed; the step asks again. */
    private const int WaitSeconds = 5;

    public function hold(DirectSeedHoldRequest $request, Operation $operation, ProofSeeds $seeds): JsonResponse
    {
        if ($operation->status->terminal()) {
            throw new ResourceOperationException('operation.finished', 'The Operation has ended.', 409);
        }
        $seconds = config('operations.seed_hold_wait_seconds', self::WaitSeconds);
        $deadline = microtime(true) + (is_int($seconds) ? max(0, $seconds) : self::WaitSeconds);
        while (! $seeds->hold($operation)) {
            if (microtime(true) >= $deadline) {
                throw new ResourceOperationException('instance.seed_busy', 'A seed copy holds the default\'s seed.', 423);
            }
            usleep(250_000);
        }

        return response()->json(['data' => ['held' => true], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
