<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\WorktreeService;
use Illuminate\Http\Request;

class WorktreeController extends Controller
{
    public function __construct(
        protected WorktreeService $worktree,
    ) {}

    /**
     * Get all worktrees for an environment.
     */
    public function index(Environment $environment)
    {
        $result = $this->worktree->worktrees($environment);

        return response()->json($result);
    }

    /**
     * Unlink a worktree from a project.
     */
    public function unlink(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'project' => 'required|string',
            'worktree' => 'required|string',
        ]);

        $result = $this->worktree->unlinkWorktree(
            $environment,
            $validated['project'],
            $validated['worktree']
        );

        return response()->json($result);
    }

    /**
     * Refresh worktree detection.
     */
    public function refresh(Environment $environment)
    {
        $result = $this->worktree->refreshWorktrees($environment);

        return response()->json($result);
    }
}
