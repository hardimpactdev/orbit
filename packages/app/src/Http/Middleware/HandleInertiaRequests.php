<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Middleware;

use HardImpact\Orbit\Core\Models\Environment;
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
    protected $rootView;

    public function __construct()
    {
        $this->rootView = config('inertia.root_view', 'orbit::app');
    }

    /**
     * Get the client-side Reverb host based on APP_URL's TLD.
     * Server connects directly to 127.0.0.1:8080, but browsers need reverb.<tld> via Caddy.
     */
    protected function getReverbClientHost(): string
    {
        $appUrl = config('app.url', 'https://orbit.test');
        $host = parse_url($appUrl, PHP_URL_HOST) ?? 'orbit.test';

        // Extract TLD (everything after first dot, e.g., "ccc" from "orbit.ccc")
        $parts = explode('.', $host);
        $tld = count($parts) > 1 ? end($parts) : 'test';

        return "reverb.{$tld}";
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        // During Vite HMR development, return a stable version to prevent
        // 409 conflicts that cause full page reloads
        if (file_exists(base_path('vendor/hardimpactdev/orbit-core/public/hot'))) {
            return 'dev';
        }

        return parent::version($request);
    }

    /**
     * Get the orbit-core version from git tags or commit hash.
     */
    protected function getOrbitVersion(): string
    {
        static $version = null;

        if ($version !== null) {
            return $version;
        }

        $packagePath = dirname(__DIR__, 3); // Navigate from src/Http/Middleware to package root

        // Try git describe first (gives us tag + commits since tag)
        $result = @shell_exec("cd {$packagePath} && git describe --tags --always 2>/dev/null");
        if ($result) {
            $version = trim($result);

            return $version;
        }

        // Fallback to short commit hash
        $result = @shell_exec("cd {$packagePath} && git rev-parse --short HEAD 2>/dev/null");
        if ($result) {
            $version = trim($result);

            return $version;
        }

        $version = 'unknown';

        return $version;
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
        $multiEnvironment = config('orbit.multi_environment');

        // Cache current environment to avoid duplicate queries
        $currentEnv = null;
        $getCurrentEnv = function () use (&$currentEnv, $multiEnvironment): ?\HardImpact\Orbit\Core\Models\Environment {
            if ($multiEnvironment) {
                return null;
            }

            if (! $currentEnv instanceof \HardImpact\Orbit\Core\Models\Environment) {
                $currentEnv = Environment::where('is_local', true)->first();
            }

            return $currentEnv;
        };

        // Compute client-side Reverb host from APP_URL's TLD
        // Server connects directly to 127.0.0.1:8080, but browsers need reverb.<tld> via Caddy
        $reverbClientHost = $this->getReverbClientHost();

        return [
            ...parent::share($request),
            'multi_environment' => $multiEnvironment,
            'orbit_version' => $this->getOrbitVersion(),
            'reverb' => [
                'enabled' => config('broadcasting.default') === 'reverb',
                'host' => $reverbClientHost,
                'port' => config('orbit-ui.ports.reverb', 443),
                'scheme' => 'https',
                'app_key' => config('reverb.apps.apps.0.key', ''),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'provisioning' => fn () => $request->session()->get('provisioning'),
            ],
            'environments' => fn () => Environment::where('status', 'active')
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get(['id', 'name', 'host', 'is_local', 'is_default']),
            'navigation' => function () use ($currentPath, $getCurrentEnv, $multiEnvironment, $request): array {
                // In web mode, get current environment from middleware injection or query
                $currentEnv = $multiEnvironment ? null : $getCurrentEnv();

                // In desktop mode, try to get environment from route parameter
                if ($multiEnvironment && $request->route('environment')) {
                    $routeEnv = $request->route('environment');
                    if ($routeEnv instanceof Environment) {
                        $currentEnv = $routeEnv;
                    }
                }

                $envId = $currentEnv?->id;

                // Build URLs based on mode
                $urlPrefix = $multiEnvironment && $envId ? "/environments/{$envId}" : '';
                $pathPrefix = $multiEnvironment && $envId ? "environments/{$envId}/" : '';

                $mainItems = [];

                // In web mode, always show navigation (single implicit environment)
                // In desktop mode, only show when an environment is selected
                if ($envId || ! $multiEnvironment) {
                    $mainItems = [
                        [
                            'title' => 'Dashboard',
                            'href' => $urlPrefix ?: '/',
                            'icon' => 'LayoutDashboard',
                            'isActive' => in_array($currentPath, [$pathPrefix ? rtrim($pathPrefix, '/') : '', '/', ''], true),
                        ],
                        [
                            'title' => 'Projects',
                            'href' => "{$urlPrefix}/projects",
                            'icon' => 'FolderGit2',
                            'isActive' => str_starts_with($currentPath, "{$pathPrefix}projects") && ! str_contains($currentPath, 'workspaces'),
                        ],
                        [
                            'title' => 'Workspaces',
                            'href' => "{$urlPrefix}/workspaces",
                            'icon' => 'Boxes',
                            'isActive' => str_starts_with($currentPath, "{$pathPrefix}workspaces"),
                        ],
                        [
                            'title' => 'Services',
                            'href' => "{$urlPrefix}/services",
                            'icon' => 'Server',
                            'isActive' => str_starts_with($currentPath, "{$pathPrefix}services"),
                        ],
                        [
                            'title' => 'Configuration',
                            'href' => "{$urlPrefix}/configuration",
                            'icon' => 'Settings2',
                            'isActive' => str_starts_with($currentPath, "{$pathPrefix}configuration"),
                        ],
                    ];
                }

                $footerItems = [];

                return [
                    'app' => [
                        'main' => [
                            'items' => $mainItems,
                        ],
                        'footer' => [
                            'items' => $footerItems,
                        ],
                    ],
                ];
            },
        ];
    }
}
