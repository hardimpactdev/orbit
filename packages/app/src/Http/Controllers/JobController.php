<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\TrackedJob;
use Illuminate\Http\JsonResponse;

class JobController extends Controller
{
    /**
     * Show the status of a tracked job.
     */
    public function show(TrackedJob $trackedJob): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $trackedJob,
            'status' => $trackedJob->status,
        ]);
    }
}
