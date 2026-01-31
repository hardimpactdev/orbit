<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\WorkspaceService;
use HardImpact\Orbit\Ui\Http\Controllers\Concerns\ProvidesRemoteApiUrl;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    use ProvidesRemoteApiUrl;

    public function __construct(
        protected WorkspaceService $workspace,
    ) {}

    /**
     * List all workspaces for an environment (Inertia page).
     */
    public function index(Environment $environment): \Inertia\Response
    {
        $editor = $environment->getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($environment);

        // Don't fetch workspaces synchronously - let Vue load them async
        return \Inertia\Inertia::render('environments/Workspaces', [
            'environment' => $environment,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
        ]);
    }

    /**
     * API endpoint for workspaces list.
     */
    public function indexApi(Environment $environment)
    {
        $result = $this->workspace->workspacesList($environment);

        // Normalize workspace data for frontend (rename keys)
        if ($result['success'] && isset($result['data']['workspaces'])) {
            $result['data']['workspaces'] = array_map(
                fn ($workspace) => $this->normalizeWorkspaceData($workspace),
                $result['data']['workspaces']
            );
        }

        return response()->json($result);
    }

    /**
     * Show the create workspace form.
     */
    public function create(Environment $environment): \Inertia\Response
    {
        return \Inertia\Inertia::render('environments/workspaces/Create', [
            'environment' => $environment,
        ]);
    }

    /**
     * Store a new workspace.
     */
    public function store(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|regex:/^[a-z0-9-]+$/',
        ]);

        $result = $this->workspace->workspaceCreate($environment, $validated['name']);

        if (! $result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to create workspace');
        }

        return redirect()->route('environments.workspaces', $environment)
            ->with('success', "Workspace '{$validated['name']}' created successfully");
    }

    /**
     * Show a single workspace.
     */
    public function show(Environment $environment, string $workspace): \Inertia\Response
    {
        $editor = $environment->getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($environment);

        // Don't fetch workspace data synchronously - let Vue load it async
        return \Inertia\Inertia::render('environments/workspaces/Show', [
            'environment' => $environment,
            'workspaceName' => $workspace,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
        ]);
    }

    /**
     * API endpoint for single workspace.
     */
    public function showApi(Environment $environment, string $workspace)
    {
        $result = $this->workspace->workspacesList($environment);
        $workspaces = $result['success'] ? ($result['data']['workspaces'] ?? []) : [];

        $workspaceData = collect($workspaces)->firstWhere('name', $workspace);

        if (! $workspaceData) {
            return response()->json([
                'success' => false,
                'error' => 'Workspace not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->normalizeWorkspaceData($workspaceData),
        ]);
    }

    /**
     * Delete a workspace.
     */
    public function destroy(Environment $environment, string $workspace)
    {
        $result = $this->workspace->workspaceDelete($environment, $workspace);

        if (! $result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to delete workspace');
        }

        return redirect()->route('environments.workspaces', $environment)
            ->with('success', "Workspace '{$workspace}' deleted successfully");
    }

    /**
     * Add a project to a workspace.
     */
    public function addProject(Request $request, Environment $environment, string $workspace)
    {
        $validated = $request->validate([
            'project' => 'required|string|max:255',
        ]);

        $result = $this->workspace->workspaceAddProject($environment, $workspace, $validated['project']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to add project to workspace',
            ]);
        }

        return response()->json([
            'success' => true,
            'workspace' => $result['data']['workspace'] ?? null,
        ]);
    }

    /**
     * Remove a project from a workspace.
     */
    public function removeProject(Environment $environment, string $workspace, string $project)
    {
        $result = $this->workspace->workspaceRemoveProject($environment, $workspace, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to remove project from workspace',
            ]);
        }

        return response()->json([
            'success' => true,
            'workspace' => $result['data']['workspace'] ?? null,
        ]);
    }

    /**
     * Normalize workspace data from CLI for frontend consistency.
     * CLI already returns 'projects' and 'project_count', so we just pass through.
     */
    protected function normalizeWorkspaceData(array $workspace): array
    {
        return $workspace;
    }
}
