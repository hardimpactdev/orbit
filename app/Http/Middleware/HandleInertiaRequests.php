<?php

namespace App\Http\Middleware;

use App\Models\Environment;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $currentPath = $request->path();

        // Cache default environment to avoid duplicate queries
        $defaultEnv = null;
        $getDefaultEnv = function () use (&$defaultEnv): ?\App\Models\Environment {
            if (! $defaultEnv instanceof \App\Models\Environment) {
                $defaultEnv = Environment::getDefault();
            }

            return $defaultEnv;
        };

        return [
            ...parent::share($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'provisioning' => fn () => $request->session()->get('provisioning'),
            ],
            'environments' => fn () => Environment::where('status', 'active')
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get(['id', 'name', 'host', 'is_local', 'is_default']),
            'currentEnvironment' => $getDefaultEnv,
            'navigation' => function () use ($currentPath, $getDefaultEnv): array {
                $currentEnv = $getDefaultEnv();
                $envId = $currentEnv?->id;
                $hasOrchestrator = $currentEnv?->orchestrator_url !== null;

                $mainItems = [];
                if ($envId) {
                    $mainItems = [
                        [
                            'title' => 'Dashboard',
                            'href' => "/environments/{$envId}",
                            'icon' => 'LayoutDashboard',
                            'isActive' => in_array($currentPath, ["environments/{$envId}", '/', ''], true),
                        ],
                        [
                            'title' => 'Projects',
                            'href' => "/environments/{$envId}/projects",
                            'icon' => 'FolderGit2',
                            'isActive' => str_starts_with($currentPath, "environments/{$envId}/projects") && ! str_contains($currentPath, 'workspaces'),
                        ],
                        [
                            'title' => 'Workspaces',
                            'href' => "/environments/{$envId}/workspaces",
                            'icon' => 'Boxes',
                            'isActive' => str_starts_with($currentPath, "environments/{$envId}/workspaces"),
                        ],
                        [
                            'title' => 'Services',
                            'href' => "/environments/{$envId}/services",
                            'icon' => 'Server',
                            'isActive' => str_starts_with($currentPath, "environments/{$envId}/services"),
                        ],
                        [
                            'title' => 'Settings',
                            'href' => "/environments/{$envId}/settings",
                            'icon' => 'Settings',
                            'isActive' => str_starts_with($currentPath, "environments/{$envId}/settings"),
                        ],
                    ];

                    // Only show Orchestrator menu item if configured
                    if ($hasOrchestrator) {
                        array_splice($mainItems, 3, 0, [[
                            'title' => 'Orchestrator',
                            'href' => "/environments/{$envId}/orchestrator",
                            'icon' => 'Workflow',
                            'isActive' => str_starts_with($currentPath, "environments/{$envId}/orchestrator"),
                        ]]);
                    }
                }

                return [
                    'app' => [
                        'main' => [
                            'items' => $mainItems,
                        ],
                        'footer' => [
                            'items' => [
                                [
                                    'title' => 'App Settings',
                                    'href' => '/settings',
                                    'icon' => 'Cog',
                                    'isActive' => $currentPath === 'settings',
                                ],
                            ],
                        ],
                    ],
                ];
            },
        ];
    }
}
