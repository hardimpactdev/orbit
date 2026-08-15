<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Workspaces\WorkspacePlacement;
use InvalidArgumentException;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class AppRuntimeContainerRenderer
{
    public const int InternalPort = 8080;

    public const string XdgConfigHome = '/tmp/orbit-frankenphp/config';

    public const string XdgDataHome = '/tmp/orbit-frankenphp/data';

    public const string XdgRoot = '/tmp/orbit-frankenphp';

    /**
     * Canonical name of the Laravel Octane FrankenPHP worker file. Generated
     * by `php artisan octane:install --server=frankenphp` and resolved
     * relative to the app's configured `document_root`.
     */
    public const string WorkerFileName = 'frankenphp-worker.php';

    public function __construct(
        private PhpRuntimePolicy $phpRuntimePolicy,
        private OrbitContainerNames $names,
        private AppRuntimeUser $appRuntimeUser = new AppRuntimeUser,
        private AppDevelopmentPackagesMount $appDevelopmentPackagesMount = new AppDevelopmentPackagesMount,
        private AppRuntimeMountService $appRuntimeMounts = new AppRuntimeMountService(new WorkspacePlacement),
        private FrankenPhpRuntimeConfigRenderer $frankenPhpConfig = new FrankenPhpRuntimeConfigRenderer,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy = new AppDevelopmentInnerTlsPolicy,
        private RuntimeClientTrustPolicy $runtimeClientTrust = new RuntimeClientTrustPolicy,
        private RuntimeHostRoutingPolicy $runtimeHostRouting = new RuntimeHostRoutingPolicy,
        private NodeHostPaths $nodeHostPaths = new NodeHostPaths,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function render(App $app, ?string $preloadPath = null): AppRuntimeContainer
    {
        $sourcePath = rtrim($app->path, '/');
        $app->loadMissing('instances');

        return $this->renderTarget(
            app: $app,
            instance: $this->placement->matchingOrbitInstanceForPath($app, $sourcePath),
            runtimeSlug: $app->name,
            preloadPath: $preloadPath,
        );
    }

    public function renderForInstance(
        App $app,
        Instance $instance,
        ?string $preloadPath = null,
    ): AppRuntimeContainer {
        return $this->renderTarget(
            app: $app,
            instance: $instance,
            runtimeSlug: $this->instanceSlug($app, $instance),
            preloadPath: $preloadPath,
        );
    }

    private function renderTarget(
        App $app,
        ?Instance $instance,
        string $runtimeSlug,
        ?string $preloadPath = null,
    ): AppRuntimeContainer {
        $runtime = $app->runtimeKind();

        if ($runtime !== AppRuntimeKind::Php) {
            throw new InvalidArgumentException(
                "App '{$app->name}' uses runtime '{$runtime->value}' and does not get a FrankenPHP runtime container.",
            );
        }

        $node = $this->placement->runtimeNode($app, $instance);
        $policy = $this->phpRuntimePolicy->forVersion(
            $this->placement->runtimePhpVersion($app, $instance),
            $preloadPath,
        );
        $sourcePath = rtrim($this->placement->runtimePath($app, $instance), '/');

        if ($sourcePath === '') {
            throw new InvalidArgumentException(
                "App '{$app->name}' has no source path; cannot render runtime container.",
            );
        }

        $mounts = [
            [
                'source' => $sourcePath,
                'target' => AppRuntimeContainer::SourceTarget,
                'read_only' => false,
            ],
            [
                'source' => $sourcePath,
                'target' => $sourcePath,
                'read_only' => false,
            ],
            [
                'source' => $this->phpIniHostPathForSlug($app, $runtimeSlug, $instance),
                'target' => AppRuntimeContainer::PhpIniMountTarget,
                'read_only' => true,
            ],
        ];

        if (($packagesMount = $this->appDevelopmentPackagesMount->forApp($app, $instance)) !== null) {
            $mounts[] = $packagesMount;
        }

        foreach ($this->appRuntimeMounts->mountsForRuntime($app, $instance) as $mount) {
            $mounts[] = $mount;
        }

        if ($node instanceof Node && $this->innerTlsPolicy->appliesToApp($app, $instance)) {
            foreach ($this->innerTlsPolicy->runtimeTlsMounts(
                $node,
                $this->innerTlsPolicy->appRouteDomain($app, $instance),
            ) as $mount) {
                $mounts[] = $mount;
            }
        }

        $mounts = array_merge($mounts, $this->runtimeClientTrust->mountsForApp($app, $instance));

        return new AppRuntimeContainer(
            name: $this->containerNameForSlug($runtimeSlug),
            image: $policy->image,
            network: $this->names->network(),
            restartPolicy: $this->restartPolicy($node),
            appSlug: $runtimeSlug,
            runtimeUser: $this->appRuntimeUser->containerUserForApp($app, $instance),
            environment: array_merge(
                $this->environmentFor($app, $instance),
                $this->runtimeClientTrust->environmentForApp($app, $instance),
            ),
            mounts: $mounts,
            networkAliases: [
                $this->containerNameForSlug($runtimeSlug),
                "app-{$runtimeSlug}",
            ],
            phpIni: array_merge(
                $policy->phpIni,
                $this->runtimeClientTrust->phpIniForApp($app, $instance),
            ),
            extraHosts: $this->runtimeHostRouting->forApp($app, $instance),
            workingDirectory: $this->applicationRootInContainer($app, $instance),
        );
    }

    private function restartPolicy(?Node $node): string
    {
        return $node?->hasActiveRole('app-dev') === true ? 'unless-stopped' : 'always';
    }

    public function containerName(App $app): string
    {
        return $this->containerNameForSlug($app->name);
    }

    public function containerNameForInstance(App $app, Instance $instance): string
    {
        return $this->containerNameForSlug($this->instanceSlug($app, $instance));
    }

    public function phpIniHostPath(App $app): string
    {
        return $this->phpIniHostPathForSlug($app, $app->name);
    }

    public function phpIniHostPathForInstance(App $app, Instance $instance): string
    {
        return $this->phpIniHostPathForSlug(
            $app,
            $this->instanceSlug($app, $instance),
            $instance,
        );
    }

    private function phpIniHostPathForSlug(App $app, string $runtimeSlug, ?Instance $instance = null): string
    {
        $node = $this->placement->runtimeNode($app, $instance);

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node; cannot render runtime config path.");
        }

        return $this->nodeHostPaths->appRuntimeConfigPath($node, $runtimeSlug);
    }

    public function upstreamUrl(App $app): string
    {
        if ($this->innerTlsPolicy->appliesToApp($app)) {
            return 'https://'.$this->containerName($app).':'.AppDevelopmentInnerTlsPolicy::InternalTlsPort;
        }

        return 'http://'.$this->containerName($app).':'.self::InternalPort;
    }

    public function targetName(App $app, ?Instance $instance = null): string
    {
        return $instance instanceof Instance ? "{$app->name}.{$instance->name}" : $app->name;
    }

    public function instanceSlug(App $app, Instance $instance): string
    {
        return "{$app->name}-{$instance->name}";
    }

    private function containerNameForSlug(string $runtimeSlug): string
    {
        return "orbit-app-{$runtimeSlug}";
    }

    public function runtimeAppForInstance(App $app, Instance $instance): App
    {
        $runtimeApp = clone $app;
        $node = $this->placement->nodeForInstance($instance);

        if ($node instanceof Node) {
            $runtimeApp->node_id = $node->id;
            $runtimeApp->setRelation('node', $node);
        }

        // The instance owns its PHP version outright. The app value is only the
        // template new instances are created from, so it must never override a
        // stored instance value here. The null fallback covers rows predating
        // the snapshot migration; it is not live inheritance.
        $runtimeApp->forceFill(array_filter([
            'php_version' => $this->filledInstanceValue($instance->php_version),
        ]));

        $config = $instance->driver_config;

        if ($config instanceof OrbitInstanceDriverConfigData) {
            $runtimeApp->forceFill(array_filter([
                'path' => $this->filledInstanceValue($config->path),
                'document_root' => $this->filledInstanceValue($config->document_root),
                'domain' => $this->filledInstanceValue($config->domain),
            ]));
        }

        return $runtimeApp;
    }

    private function filledInstanceValue(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(App $app, ?Instance $instance): array
    {
        $environment = [
            'APP_BASE_PATH' => $this->applicationRootInContainer($app, $instance),
            'SERVER_ROOT' => $this->documentRootInContainer($app, $instance),
            'XDG_CONFIG_HOME' => self::XdgConfigHome,
            'XDG_DATA_HOME' => self::XdgDataHome,
            'ORBIT_APP' => $app->name,
            'ORBIT_APP_DOCUMENT_ROOT' => $this->placement->runtimeDocumentRoot($app, $instance),
            'ORBIT_PHP_VERSION' => $this->placement->runtimePhpVersion($app, $instance),
        ];

        if ($this->innerTlsPolicy->appliesToApp($app, $instance)) {
            $environment = array_merge(
                $environment,
                $this->innerTlsPolicy->runtimeTlsEnvironment($this->innerTlsPolicy->appRouteDomain($app, $instance)),
            );
        } else {
            $environment['SERVER_NAME'] = ':'.self::InternalPort;
        }

        if ($instance instanceof Instance && $instance->worker_enabled) {
            $environment = array_merge($environment, $this->workerEnvironmentFor($app, $instance));

            return $environment;
        }

        $frankenPhpConfig = $this->frankenPhpConfig->classic($app, $instance);

        if ($frankenPhpConfig !== null) {
            $environment['FRANKENPHP_CONFIG'] = $frankenPhpConfig;
        }

        return $environment;
    }

    /**
     * Worker-mode env vars are emitted only when the instance enables worker mode.
     * The instance's stored worker_config is the single source of truth; classic mode is
     * the default and emits none of these vars.
     *
     * Active levers:
     * - `FRANKENPHP_CONFIG`: FrankenPHP natively reads this as a Caddyfile
     *   snippet at boot. App-dev runtimes add thread-pool settings here; worker
     *   mode appends the documented block form against Laravel Octane's
     *   FrankenPHP worker file.
     * - `MAX_REQUESTS`: Laravel's stock `public/frankenphp-worker.php` reads
     *   `$_SERVER['MAX_REQUESTS']` and recycles the worker after that many
     *   requests.
     *
     * @return array<string, string>
     */
    private function workerEnvironmentFor(App $app, Instance $instance): array
    {
        $config = $instance->workerConfig();
        $workerFile = $this->frankenPhpWorkerFilePath($app, $instance);

        return [
            'FRANKENPHP_CONFIG' => $this->frankenPhpConfig->worker($app, $workerFile, $config->workers, $instance),
            'MAX_REQUESTS' => (string) $config->maxRequests,
        ];
    }

    public function frankenPhpWorkerFilePath(App $app, ?Instance $instance = null): string
    {
        return $this->documentRootInContainer($app, $instance).'/'.self::WorkerFileName;
    }

    public function documentRootInContainer(App $app, ?Instance $instance = null): string
    {
        $documentRoot = trim($this->placement->runtimeDocumentRoot($app, $instance), characters: '/');

        if ($documentRoot === '' || $documentRoot === '.') {
            return AppRuntimeContainer::SourceTarget;
        }

        return AppRuntimeContainer::SourceTarget.'/'.$documentRoot;
    }

    public function applicationRootInContainer(App $app, ?Instance $instance = null): string
    {
        $documentRoot = trim($this->placement->runtimeDocumentRoot($app, $instance), characters: '/');

        if ($documentRoot === 'live' || str_starts_with($documentRoot, 'live/')) {
            return AppRuntimeContainer::SourceTarget.'/live';
        }

        return AppRuntimeContainer::SourceTarget;
    }

    /**
     * Worker file path relative to the app source root. Renderer and the
     * readiness validator must agree on this so what readiness checks
     * matches what the runtime points `FRANKENPHP_CONFIG` at.
     */
    public static function workerFileRelativeToSource(App $app): string
    {
        $documentRoot = trim($app->document_root, characters: '/');

        if ($documentRoot === '' || $documentRoot === '.') {
            return self::WorkerFileName;
        }

        return $documentRoot.'/'.self::WorkerFileName;
    }
}
