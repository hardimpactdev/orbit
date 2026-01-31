<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Services\SetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function __construct(
        protected SetupService $setup,
    ) {}

    /**
     * Show the setup page.
     */
    public function index(): Response
    {
        return Inertia::render('Setup', [
            'status' => $this->setup->getStatus(),
        ]);
    }

    /**
     * Check if setup is needed (API endpoint).
     */
    public function check(): JsonResponse
    {
        return response()->json($this->setup->getStatus());
    }

    /**
     * Run the setup process.
     */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tld' => 'nullable|string|max:20|alpha_num',
        ]);

        $tld = $validated['tld'] ?? 'test';

        $result = $this->setup->runSetupSync($tld);

        return response()->json($result);
    }

    /**
     * Get setup progress (for polling).
     */
    public function status(): JsonResponse
    {
        return response()->json($this->setup->getStatus());
    }
}
