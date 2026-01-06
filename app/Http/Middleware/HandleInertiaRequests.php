<?php

namespace App\Http\Middleware;

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

        return [
            ...parent::share($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'navigation' => [
                'app' => [
                    'main' => [
                        'items' => [
                            [
                                'title' => 'Dashboard',
                                'href' => '/',
                                'icon' => 'LayoutDashboard',
                                'isActive' => $currentPath === '/' || $currentPath === '',
                            ],
                            [
                                'title' => 'Environments',
                                'href' => '/servers',
                                'icon' => 'Server',
                                'isActive' => str_starts_with($currentPath, 'servers'),
                            ],
                            [
                                'title' => 'Provision',
                                'href' => '/provision',
                                'icon' => 'Rocket',
                                'isActive' => str_starts_with($currentPath, 'provision'),
                            ],
                        ],
                    ],
                    'footer' => [
                        'items' => [
                            [
                                'title' => 'Settings',
                                'href' => '/settings',
                                'icon' => 'Settings',
                                'isActive' => str_starts_with($currentPath, 'settings'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
