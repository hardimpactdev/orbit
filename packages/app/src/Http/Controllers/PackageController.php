<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\PackageService;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function __construct(
        protected PackageService $package,
    ) {}

    /**
     * Get linked packages for a project.
     */
    public function index(Environment $environment, string $project)
    {
        $result = $this->package->packageLinked($environment, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to get linked packages',
            ]);
        }

        return response()->json([
            'success' => true,
            'linked_packages' => $result['data']['linked_packages'] ?? [],
        ]);
    }

    /**
     * Link a package to a project.
     */
    public function link(Request $request, Environment $environment, string $project)
    {
        $package = $request->input('package');

        if (! $package) {
            return response()->json([
                'success' => false,
                'error' => 'Package name is required',
            ], 400);
        }

        $result = $this->package->packageLink($environment, $package, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to link package',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $result['data']['message'] ?? 'Package linked successfully',
        ]);
    }

    /**
     * Unlink a package from a project.
     */
    public function unlink(Environment $environment, string $project, string $package)
    {
        $result = $this->package->packageUnlink($environment, $package, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to unlink package',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $result['data']['message'] ?? 'Package unlinked successfully',
        ]);
    }
}
