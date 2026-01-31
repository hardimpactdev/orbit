<?php

namespace App\Http\Controllers;

use App\Services\CliInstallService;
use Illuminate\Http\JsonResponse;

class CliController extends Controller
{
    public function __construct(
        protected CliInstallService $cliService
    ) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'installed' => $this->cliService->isInstalled(),
            'version' => $this->cliService->getVersion(),
            'bundled_path' => $this->cliService->getBundledCliPath(),
        ]);
    }

    public function install(): JsonResponse
    {
        $success = $this->cliService->install();

        return response()->json([
            'success' => $success,
            'installed' => $this->cliService->isInstalled(),
            'version' => $this->cliService->getVersion(),
            'message' => $success
                ? 'Orbit CLI installed successfully! You can now use "orbit" from your terminal.'
                : 'Failed to install CLI. Please try again or install manually.',
        ]);
    }
}
