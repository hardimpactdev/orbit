<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\CreateProjectRequest;
use HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\DeleteProjectRequest;
use HardImpact\Orbit\Core\Services\EnvironmentManager;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\ConnectorService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        protected ConnectorService $connector,
        protected EnvironmentManager $environments,
        protected ConfigurationService $config,
    ) {}

    /**
     * Create a new project in the active environment.
     * Always uses the Saloon API connector for consistency.
     */
    public function store(Request $request)
    {
        $environment = $this->environments->current();

        if (! $environment) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active environment',
                ], 400);
            }

            return redirect()->back()->withErrors(['error' => 'No active environment']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'org' => 'nullable|string|max:255',
            'template' => 'nullable|string|max:255',
            'is_template' => 'boolean',
            'fork' => 'boolean',
            'visibility' => 'nullable|string|in:private,public',
            'php_version' => 'nullable|string',
            'db_driver' => 'nullable|string',
            'session_driver' => 'nullable|string',
            'cache_driver' => 'nullable|string',
            'queue_driver' => 'nullable|string',
        ]);

        $result = $this->connector->sendRequest(
            $environment,
            new CreateProjectRequest($validated)
        );

        if (! $result['success']) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to create project',
                ], 422);
            }

            return redirect()->back()->withErrors(['error' => $result['error'] ?? 'Failed to create project']);
        }

        // API requests get JSON response
        if ($request->wantsJson()) {
            return response()->json($result);
        }

        // Web requests get redirect with provisioning slug for WebSocket tracking
        $slug = $result['slug'] ?? $result['data']['slug'] ?? null;

        return redirect()->route('environments.projects', ['environment' => $environment->id])
            ->with([
                'provisioning' => $slug,
                'success' => "Project '{$validated['name']}' is being created...",
            ]);
    }

    /**
     * Delete a project from the active environment.
     * Always uses the Saloon API connector for consistency.
     */
    public function destroy(Request $request, string $slug)
    {
        $environment = $this->environments->current();

        if (! $environment) {
            return response()->json([
                'success' => false,
                'error' => 'No active environment',
            ], 400);
        }

        $keepDb = $request->boolean('keep_db', false);

        $result = $this->connector->sendRequest(
            $environment,
            new DeleteProjectRequest($slug, $keepDb)
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete project',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Set the PHP version for a project in the active environment.
     */
    public function setPhpVersion(Request $request, string $project)
    {
        $environment = $this->environments->current();

        if (! $environment) {
            return response()->json([
                'success' => false,
                'error' => 'No active environment',
            ], 400);
        }

        $validated = $request->validate([
            'version' => 'required|string',
        ]);

        $result = $this->config->php($environment, $project, $validated['version']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to update PHP version',
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Reset the PHP version for a project to the environment default.
     */
    public function resetPhpVersion(string $project)
    {
        $environment = $this->environments->current();

        if (! $environment) {
            return response()->json([
                'success' => false,
                'error' => 'No active environment',
            ], 400);
        }

        $result = $this->config->phpReset($environment, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to reset PHP version',
            ], 422);
        }

        return response()->json($result);
    }
}
