<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\NodeRuntimeConfigsProbeStatus;
use App\Enums\Apps\NodeRuntimeContainersProbeStatus;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;

final readonly class AppsProbe
{
    public function __construct(
        private ?AppRuntimeUser $appRuntimeUser = null,
        private ?AppRuntimeContainerRenderer $appRuntimeContainerRenderer = null,
        private ?PhpRuntimeCatalog $phpRuntimeCatalog = null,
        private ?AppAgentIdeDefaults $agentIdeDefaults = null,
        private ?NodeRoleAssignments $nodeRoleAssignments = null,
        private ?RemoteAppIntrospectProbe $introspectProbe = null,
        private ?RemoteAppRuntimeConfigsProbe $runtimeConfigsProbe = null,
        private ?RemoteAppRuntimeContainersProbe $runtimeContainersProbe = null,
    ) {}

    public function key(): string
    {
        return 'app';
    }

    public function label(): string
    {
        return 'Apps';
    }

    public function introspect(App $app): ProbeSnapshot
    {
        $app->loadMissing('node');

        if (! $app->node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        return $this->snapshotFromProbe(
            snapshot: $this->introspectProbe()->snapshot($app->node, $this->introspectionPayload($app)),
            fallbackName: $app->name,
        );
    }

    /**
     * @return array<string, string>
     */
    private function introspectionPayload(App $app): array
    {
        $isPhpApp = $app->runtimeKind() === AppRuntimeKind::Php;
        $containerName = $isPhpApp ? $this->appRuntimeContainerRenderer()->containerName($app) : '';
        $expectedSpecHash = '';
        $expectedRuntimeConfigHash = '';
        $runtimeConfigPath = '';
        $expectedImage = '';

        if ($isPhpApp) {
            try {
                $renderedContainer = $this->appRuntimeContainerRenderer()->render($app);
                $expectedSpecHash = $renderedContainer->specHash();
                $expectedRuntimeConfigHash = hash('sha256', $renderedContainer->phpIniContent());
                $runtimeConfigPath = $this->appRuntimeContainerRenderer()->phpIniHostPath($app);
                $expectedImage = $renderedContainer->image();
            } catch (\Throwable) {
                $expectedSpecHash = '';
                $expectedRuntimeConfigHash = '';
                $runtimeConfigPath = '';
                $expectedImage = '';
            }
        }

        return [
            'name' => $app->name,
            'path' => rtrim($app->path, '/'),
            'document_root' => $app->document_root,
            'runtime_kind' => $app->runtimeKind()->value,
            'runtime_user' => $this->appRuntimeUser()->forApp($app),
            'runtime_container_name' => $containerName,
            'expected_spec_hash' => $expectedSpecHash,
            'runtime_config_path' => $runtimeConfigPath,
            'expected_runtime_config_hash' => $expectedRuntimeConfigHash,
            'expected_runtime_image' => $expectedImage,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotFromProbe(array $snapshot, string $fallbackName): ProbeSnapshot
    {
        $name = is_string($snapshot['name'] ?? null) ? $snapshot['name'] : $fallbackName;

        return new ProbeSnapshot([
            $name => [
                'path_exists' => $this->snapshotBool($snapshot, 'path_exists'),
                'root_exists' => $this->snapshotBool($snapshot, 'root_exists'),
                'root_inside_path' => $this->snapshotBool($snapshot, 'root_inside_path'),
                'docker_available' => $this->snapshotBool($snapshot, 'docker_available'),
                'container_exists' => $this->snapshotBool($snapshot, 'container_exists'),
                'container_spec_matches' => $this->snapshotBool($snapshot, 'container_spec_matches'),
                'container_running' => $this->snapshotBool($snapshot, 'container_running'),
                'system_user_exists' => $this->snapshotBool($snapshot, 'system_user_exists'),
                'fs_permissions_ok' => $this->snapshotBool($snapshot, 'fs_permissions_ok'),
                'runtime_config_exists' => $this->snapshotBool($snapshot, 'runtime_config_exists'),
                'runtime_config_matches' => $this->snapshotBool($snapshot, 'runtime_config_matches'),
                'runtime_image_available' => $this->snapshotBool($snapshot, 'runtime_image_available'),
                'runtime_image_probe_failed' => $this->snapshotBool($snapshot, 'runtime_image_probe_failed'),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotBool(array $snapshot, string $key): bool
    {
        return ($snapshot[$key] ?? false) === true;
    }

    private function introspectProbe(): RemoteAppIntrospectProbe
    {
        return $this->introspectProbe ?? app(RemoteAppIntrospectProbe::class);
    }

    /**
     * Probe the node for Orbit-managed runtime config files
     * (`~/.config/orbit/apps/<slug>.ini`). Returns a tri-state probe result so the
     * orchestrator can distinguish proven-absent (no orphan scan needed) from
     * sudo/SSH/probe failure (must NOT be reported as a clean empty snapshot,
     * because stale `runtime_config_extra` artifacts could be hidden).
     */
    public function introspectNodeRuntimeConfigs(Node $node): NodeRuntimeConfigsProbe
    {
        try {
            $stdout = $this->runtimeConfigsProbe()->stdout($node);
        } catch (\Throwable $exception) {
            return new NodeRuntimeConfigsProbe(
                status: NodeRuntimeConfigsProbeStatus::Error,
                configs: new ProbeSnapshot([]),
                error: $exception->getMessage(),
            );
        }

        $lines = explode("\n", rtrim($stdout, "\n\r"));
        $status = NodeRuntimeConfigsProbeStatus::Error;
        $error = 'orbit-config-dir probe returned no status sentinel';
        $items = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'orbit-config-dir:')) {
                $marker = substr($line, strlen('orbit-config-dir:'));

                if ($marker === 'present') {
                    $status = NodeRuntimeConfigsProbeStatus::Present;
                    $error = '';
                } elseif ($marker === 'absent') {
                    $status = NodeRuntimeConfigsProbeStatus::Absent;
                    $error = '';
                } else {
                    $status = NodeRuntimeConfigsProbeStatus::Error;
                    $error = ltrim(substr($marker, strlen('error')));

                    if ($error === '') {
                        $error = 'unknown sudo / docker probe failure';
                    }
                }

                continue;
            }

            $path = $line;
            $basename = basename($path);
            $slug = preg_replace('/\.ini$/', '', $basename) ?? '';

            if ($slug === '') {
                continue;
            }

            $items[$slug] = [
                'path' => $path,
                'app_slug' => $slug,
            ];
        }

        // Only Present probes may surface listed files; an Error sentinel
        // followed by file output should not be trusted as a clean orphan
        // list. Absent probes return an empty snapshot by definition.
        $configs = $status === NodeRuntimeConfigsProbeStatus::Present
            ? new ProbeSnapshot($items)
            : new ProbeSnapshot([]);

        return new NodeRuntimeConfigsProbe(
            status: $status,
            configs: $configs,
            error: $error,
        );
    }

    private function runtimeConfigsProbe(): RemoteAppRuntimeConfigsProbe
    {
        return $this->runtimeConfigsProbe ?? app(RemoteAppRuntimeConfigsProbe::class);
    }

    private function runtimeContainersProbe(): RemoteAppRuntimeContainersProbe
    {
        return $this->runtimeContainersProbe ?? app(RemoteAppRuntimeContainersProbe::class);
    }

    /**
     * Probe the node for Orbit-managed app runtime containers regardless of
     * gateway app records. Returns a tri-state probe result so the
     * orchestrator can distinguish:
     *
     * - **Present**: docker scanned successfully; the snapshot is keyed by
     *   the encoded app slug label and is authoritative for orphan detection.
     * - **Absent**: docker is not installed on the node (no Orbit-managed
     *   runtime containers can exist) — an empty snapshot is correct.
     * - **Error**: docker container ls failed for an unknown reason (daemon
     *   down, permission denied, SSH/remote transport error). Empty snapshot
     *   is returned so the orchestrator does NOT mistake it for a clean
     *   absence and silently hide stale `app.runtime_container_extra`
     *   artifacts.
     *
     * Probe failure must never abort the whole doctor run; the caller maps
     * Error status to a dedicated `app.runtime_container_probe_failed` drift.
     */
    public function introspectNode(Node $node): NodeRuntimeContainersProbe
    {
        try {
            $stdout = $this->runtimeContainersProbe()->stdout($node);
        } catch (\Throwable $exception) {
            return new NodeRuntimeContainersProbe(
                status: NodeRuntimeContainersProbeStatus::Error,
                containers: new ProbeSnapshot([]),
                error: $exception->getMessage(),
            );
        }

        $status = NodeRuntimeContainersProbeStatus::Error;
        $error = 'orbit-container-scan probe returned no status sentinel';
        $items = [];

        foreach (explode("\n", rtrim($stdout, "\n\r")) as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'orbit-container-scan:')) {
                $marker = substr($line, strlen('orbit-container-scan:'));

                if ($marker === 'present') {
                    $status = NodeRuntimeContainersProbeStatus::Present;
                    $error = '';
                } elseif ($marker === 'absent') {
                    $status = NodeRuntimeContainersProbeStatus::Absent;
                    $error = '';
                } else {
                    $status = NodeRuntimeContainersProbeStatus::Error;
                    $error = ltrim(substr($marker, strlen('error')));

                    if ($error === '') {
                        $error = 'unknown docker container ls failure';
                    }
                }

                continue;
            }

            $parts = explode("\t", $rawLine, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$containerName, $appSlug] = $parts;
            $appSlug = trim($appSlug);

            if ($appSlug === '') {
                continue;
            }

            $items[$appSlug] = [
                'container_name' => trim($containerName),
                'app_slug' => $appSlug,
            ];
        }

        // Only Present probes surface listed containers; Absent/Error return
        // an empty snapshot so doctor cannot mistake them for a clean orphan
        // list.
        $containers = $status === NodeRuntimeContainersProbeStatus::Present
            ? new ProbeSnapshot($items)
            : new ProbeSnapshot([]);

        return new NodeRuntimeContainersProbe(
            status: $status,
            containers: $containers,
            error: $error,
        );
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(App $app, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($app));
        $drift = array_merge($drift, $this->checkOwnerNode($app));
        $drift = array_merge($drift, $this->checkSourcePath($app, $snapshot));
        $drift = array_merge($drift, $this->checkDocumentRoot($app, $snapshot));
        $drift = array_merge($drift, $this->checkPhpRuntime($app, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeContainer($app, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeConfig($app, $snapshot));
        $drift = array_merge($drift, $this->checkProductionSecurity($app, $snapshot));
        $drift = array_merge($drift, $this->checkAgentIdeDefault($app));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(App $app): array
    {
        if (
            ! is_string($app->name)
            || $app->name === ''
            || ! is_int($app->node_id)
            || ! is_string($app->environment)
            || $app->environment === ''
            || ! is_string($app->path)
            || $app->path === ''
            || ! is_string($app->document_root)
            || $app->document_root === ''
            || ! is_string($app->php_version)
            || $app->php_version === ''
            || ! is_bool($app->adopted)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "App record for {$app->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeContainer(App $app, ProbeSnapshot $snapshot): array
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return [];
        }

        $observed = $snapshot->get($app->name);

        if (
            $observed === null
            || ($observed['path_exists'] ?? null) === false
            || ($observed['docker_available'] ?? null) === false
        // When the selected image is proven absent the runtime container
        // cannot exist either; checkPhpRuntime emits the canonical
        // `app.php_version_unavailable` drift in that case. Unknown
        // image-probe failures (Docker daemon unreachable, permission,
        // transport error) do NOT short-circuit here — they fall through
        // to surface as the documented `app.runtime_container_missing`
        // (when no container exists) or `app.runtime_container_mismatch`
        // (when one does) so the doctor restore path can re-attempt apply.
        ) {
            return [];
        }

        if (
            ($observed['runtime_image_available'] ?? null) === false
            && ($observed['runtime_image_probe_failed'] ?? null) !== true
        ) {
            // Image proven missing: checkPhpRuntime owns the
            // `app.php_version_unavailable` drift; suppress container drift
            // because the container cannot exist without its image.
            return [];
        }

        if (($observed['container_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_container_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} FrankenPHP runtime container is missing on the owning app node.",
                    detail: [
                        'expected' => $this->appRuntimeContainerRenderer()->containerName($app),
                    ],
                ),
            ];
        }

        if (($observed['container_spec_matches'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_container_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} FrankenPHP runtime container differs from gateway app configuration.",
                    detail: [
                        'expected' => $this->appRuntimeContainerRenderer()->containerName($app),
                    ],
                ),
            ];
        }

        // A stopped container exposes no runtime endpoint, so doctor docs
        // treat it as `app.runtime_container_missing` ("absent endpoint")
        // even though the container record itself still exists. Restore
        // restarts it via AppRuntimeContainerManager::apply().
        if (($observed['container_running'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_container_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} FrankenPHP runtime container is stopped; runtime endpoint is absent.",
                    detail: [
                        'expected' => $this->appRuntimeContainerRenderer()->containerName($app),
                        'reason' => 'container_stopped',
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeConfig(App $app, ProbeSnapshot $snapshot): array
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return [];
        }

        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        $expectedPath = $this->appRuntimeContainerRenderer()->phpIniHostPath($app);

        if (($observed['runtime_config_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_config_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} managed runtime configuration is missing on the owning app node.",
                    detail: [
                        'app' => $app->name,
                        'expected' => $expectedPath,
                    ],
                ),
            ];
        }

        if (($observed['runtime_config_matches'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_config_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} managed runtime configuration differs from gateway app configuration.",
                    detail: [
                        'app' => $app->name,
                        'expected' => $expectedPath,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkPhpRuntime(App $app, ProbeSnapshot $snapshot): array
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return [];
        }

        if (! $this->phpRuntimeCatalog()->supports($app->php_version)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "PHP {$app->php_version} is not a supported FrankenPHP runtime image for app {$app->name}.",
                    detail: [
                        'php_version' => $app->php_version,
                    ],
                ),
            ];
        }

        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['docker_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "Docker is not available to serve PHP {$app->php_version} for app {$app->name} on the owning app node.",
                    detail: [
                        'php_version' => $app->php_version,
                    ],
                ),
            ];
        }

        // Unknown image probe failures (Docker daemon unreachable, permission
        // denied, SSH transport error) MUST NOT collapse into
        // `app.php_version_unavailable` — that drift means the selected
        // FrankenPHP image is proven missing on the node. Suppress here;
        // checkRuntimeContainer surfaces the documented
        // `app.runtime_container_missing` / `app.runtime_container_mismatch`
        // drift instead, so doctor restore can re-attempt apply through the
        // manager's image preflight (which throws
        // AppRuntimeContainerApplyException on persistent probe failure).
        if (($observed['runtime_image_probe_failed'] ?? null) === true) {
            return [];
        }

        if (($observed['runtime_image_available'] ?? null) === false) {
            $expectedImage = $this->expectedImageOrEmpty($app);

            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "FrankenPHP runtime image for PHP {$app->php_version} is not available on the owning app node for app {$app->name}.",
                    detail: [
                        'php_version' => $app->php_version,
                        'expected_image' => $expectedImage,
                    ],
                ),
            ];
        }

        return [];
    }

    private function expectedImageOrEmpty(App $app): string
    {
        try {
            return $this->appRuntimeContainerRenderer()->render($app)->image();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkProductionSecurity(App $app, ProbeSnapshot $snapshot): array
    {
        if (! $this->isProductionApp($app)) {
            return [];
        }

        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (! array_key_exists('system_user_exists', $observed)) {
            return [];
        }

        $drift = [];

        if (($observed['system_user_exists'] ?? null) === false) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'app.security.system_user',
                kind: DriftKind::Missing,
                summary: "Production app {$app->name} is missing its expected runtime user.",
                detail: [
                    'app' => $app->name,
                    'runtime_user' => $this->appRuntimeUser()->forApp($app),
                ],
            );
        }

        if (($observed['fs_permissions_ok'] ?? null) === false) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'app.security.fs_permissions',
                kind: DriftKind::Divergent,
                summary: "Production app {$app->name} filesystem permissions do not match runtime policy.",
                detail: [
                    'app' => $app->name,
                    'path' => $app->path,
                    'runtime_user' => $this->appRuntimeUser()->forApp($app),
                ],
            );
        }

        if (
            $app->runtimeKind() === AppRuntimeKind::Php
            && ($observed['docker_available'] ?? null) === true
            && (($observed['container_exists'] ?? null) === false
            || ($observed['container_spec_matches'] ?? null) === false)
        ) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'app.security.runtime_container_isolation',
                kind: $observed['container_exists'] === false ? DriftKind::Missing : DriftKind::Divergent,
                summary: "Production app {$app->name} runtime container isolation does not match security policy.",
                detail: [
                    'app' => $app->name,
                    'expected' => $this->appRuntimeContainerRenderer()->containerName($app),
                ],
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwnerNode(App $app): array
    {
        $app->loadMissing('node');

        if (! $app->node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.owner_node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} points at a missing owning node.",
                ),
            ];
        }

        $requiredRole = $app->environment === 'production' ? 'app-prod' : 'app-dev';

        if (! $app->node->isActive() || ! $this->nodeRoleAssignments()->nodeHasActiveRole($app->node, $requiredRole)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.owner_node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} is owned by node {$app->node->name}, which is not an active app node.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkSourcePath(App $app, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($app->name);

        if ($observed === null) {
            return [];
        }

        if (($observed['path_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.path_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} path is missing on the owning app node.",
                    detail: [
                        'expected' => $app->path,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDocumentRoot(App $app, ProbeSnapshot $snapshot): array
    {
        if ($app->path === '' || $app->document_root === '') {
            return [];
        }

        if ($this->documentRootEscapesPath($app)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_outside_path',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} document root resolves outside the app path.",
                    detail: [
                        'path' => $app->path,
                        'document_root' => $app->document_root,
                    ],
                ),
            ];
        }

        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['root_inside_path'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_outside_path',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} document root resolves outside the app path.",
                    detail: [
                        'path' => $app->path,
                        'document_root' => $app->document_root,
                    ],
                ),
            ];
        }

        if (($observed['root_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} document root is missing on the owning app node.",
                    detail: [
                        'expected' => $app->documentRootPath(),
                    ],
                ),
            ];
        }

        return [];
    }

    private function documentRootEscapesPath(App $app): bool
    {
        $path = $this->normalizePath($app->path);
        $root = trim($app->document_root, '/');
        $rootPath = $root === '' ? $path : $this->normalizePath("{$app->path}/{$root}");

        return $rootPath !== $path && ! str_starts_with($rootPath, "{$path}/");
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkAgentIdeDefault(App $app): array
    {
        $config = $app->agent_ide_config ?? [];

        if (! is_array($config) || $config === []) {
            return [];
        }

        $adapter = $config['adapter'] ?? null;

        if ($adapter === null || $adapter === '') {
            return [];
        }

        if (! is_string($adapter) || ! in_array($adapter, $this->agentIdeDefaults()->supportedAdapters(), true)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.agent_ide_default_invalid',
                    kind: DriftKind::Divergent,
                    summary: "App agent IDE adapter for {$app->name} is not supported.",
                ),
            ];
        }

        return [];
    }

    private function appRuntimeUser(): AppRuntimeUser
    {
        return $this->appRuntimeUser ?? app(AppRuntimeUser::class);
    }

    private function appRuntimeContainerRenderer(): AppRuntimeContainerRenderer
    {
        return $this->appRuntimeContainerRenderer ?? app(AppRuntimeContainerRenderer::class);
    }

    private function phpRuntimeCatalog(): PhpRuntimeCatalog
    {
        return $this->phpRuntimeCatalog ?? app(PhpRuntimeCatalog::class);
    }

    private function agentIdeDefaults(): AppAgentIdeDefaults
    {
        return $this->agentIdeDefaults ?? app(AppAgentIdeDefaults::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function isProductionApp(App $app): bool
    {
        $app->loadMissing('node');

        return (
            $app->environment === 'production'
            || $app->node instanceof Node
            && $this->nodeRoleAssignments()->nodeHasActiveRole($app->node, 'app-prod')
        );
    }
}
