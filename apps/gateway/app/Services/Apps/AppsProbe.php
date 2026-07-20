<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\NodeRuntimeConfigsProbeStatus;
use App\Enums\DriftKind;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use Throwable;

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
    ) {}

    public function key(): string
    {
        return 'app';
    }

    public function label(): string
    {
        return 'Apps';
    }

    public function introspect(Project $app): ProbeSnapshot
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

    public function introspectInstance(Project $app, AppInstance $instance): ProbeSnapshot
    {
        $runtimeApp = $this->appRuntimeContainerRenderer()->runtimeAppForInstance($app, $instance);
        $runtimeApp->loadMissing('node');

        if (! $runtimeApp->node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        return $this->snapshotFromProbe(
            snapshot: $this->introspectProbe()->snapshot(
                $runtimeApp->node,
                $this->introspectionPayload($app, $instance),
            ),
            fallbackName: $this->appRuntimeContainerRenderer()->targetName($app, $instance),
        );
    }

    /**
     * @return array<string, string>
     */
    private function introspectionPayload(Project $app, ?AppInstance $instance = null): array
    {
        $renderer = $this->appRuntimeContainerRenderer();
        $runtimeApp = $instance instanceof AppInstance ? $renderer->runtimeAppForInstance($app, $instance) : $app;
        $isPhpApp = $runtimeApp->runtimeKind() === AppRuntimeKind::Php;
        $containerName = '';

        if ($isPhpApp) {
            $containerName = $instance instanceof AppInstance
                ? $renderer->containerNameForInstance($app, $instance)
                : $renderer->containerName($runtimeApp);
        }
        $expectedSpecHash = '';
        $expectedRuntimeConfigHash = '';
        $runtimeConfigPath = '';
        $expectedImage = '';

        if ($isPhpApp) {
            try {
                $renderedContainer = $instance instanceof AppInstance
                    ? $renderer->renderForInstance($app, $instance)
                    : $renderer->render($runtimeApp);
                $expectedSpecHash = $renderedContainer->specHash();
                $expectedRuntimeConfigHash = hash('sha256', $renderedContainer->phpIniContent());
                $runtimeConfigPath = $instance instanceof AppInstance
                    ? $renderer->phpIniHostPathForInstance($app, $instance)
                    : $renderer->phpIniHostPath($runtimeApp);
                $expectedImage = $renderedContainer->image();
            } catch (Throwable) {
                $expectedSpecHash = '';
                $expectedRuntimeConfigHash = '';
                $runtimeConfigPath = '';
                $expectedImage = '';
            }
        }

        return [
            'name' => $renderer->targetName($app, $instance),
            'path' => rtrim($runtimeApp->path, '/'),
            'document_root' => $runtimeApp->document_root,
            'runtime_kind' => $runtimeApp->runtimeKind()->value,
            'runtime_user' => $this->appRuntimeUser()->forApp($runtimeApp),
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
        } catch (Throwable $exception) {
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

    /**
     * @return list<DriftEntry>
     */
    public function diff(Project $app, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($app));
        $drift = array_merge($drift, $this->checkOwnerNode($app));
        $drift = array_merge($drift, $this->checkSourcePath($app, $snapshot));
        $drift = array_merge($drift, $this->checkDocumentRoot($app, $snapshot));
        $drift = array_merge($drift, $this->checkPhpRuntime($app, $snapshot));
        $drift = array_merge($drift, $this->checkRuntimeConfig($app, $snapshot));
        $drift = array_merge($drift, $this->checkProductionSecurity($app, $snapshot));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    public function diffInstance(Project $app, AppInstance $instance, ProbeSnapshot $snapshot): array
    {
        $runtimeApp = $this->appRuntimeContainerRenderer()->runtimeAppForInstance($app, $instance);
        $targetName = $this->appRuntimeContainerRenderer()->targetName($app, $instance);

        $drift = [];
        $drift = array_merge($drift, $this->checkSourcePath($runtimeApp, $snapshot, $targetName, $app, $instance));
        $drift = array_merge($drift, $this->checkDocumentRoot($runtimeApp, $snapshot, $targetName, $app, $instance));
        $drift = array_merge($drift, $this->checkPhpRuntime($runtimeApp, $snapshot, $targetName, $app, $instance));
        $drift = array_merge($drift, $this->checkRuntimeConfig($runtimeApp, $snapshot, $targetName, $app, $instance));
        $drift = array_merge($drift, $this->checkAgentIdeDefault($app, $instance));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Project $app): array
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
    private function checkRuntimeConfig(
        Project $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?Project $canonicalApp = null,
        ?AppInstance $instance = null,
    ): array {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return [];
        }

        $targetName ??= $app->name;
        $canonicalApp ??= $app;
        $observed = $snapshot->get($targetName);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        $expectedPath = $instance instanceof AppInstance
            ? $this->appRuntimeContainerRenderer()->phpIniHostPathForInstance($canonicalApp, $instance)
            : $this->appRuntimeContainerRenderer()->phpIniHostPath($app);

        if (($observed['runtime_config_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_config_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$targetName} managed runtime configuration is missing on the owning app node.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'expected' => $expectedPath,
                    ]),
                ),
            ];
        }

        if (($observed['runtime_config_matches'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.runtime_config_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "App {$targetName} managed runtime configuration differs from gateway app configuration.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'expected' => $expectedPath,
                    ]),
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkPhpRuntime(
        Project $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?Project $canonicalApp = null,
        ?AppInstance $instance = null,
    ): array {
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

        $targetName ??= $app->name;
        $canonicalApp ??= $app;
        $observed = $snapshot->get($targetName);

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

        // Unknown image probe failures must not collapse into
        // `app.php_version_unavailable`; that key means the selected image is
        // proven missing. Concrete runtime-unit failures are process-family
        // drift, so the app family emits no substitute container issue here.
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
                    summary: "FrankenPHP runtime image for PHP {$app->php_version} is not available on the owning app node for app {$targetName}.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'php_version' => $app->php_version,
                        'expected_image' => $expectedImage,
                    ]),
                ),
            ];
        }

        return [];
    }

    private function expectedImageOrEmpty(Project $app): string
    {
        try {
            return $this->appRuntimeContainerRenderer()->render($app)->image();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function targetDetail(Project $app, ?AppInstance $instance, array $detail = []): array
    {
        $target = [
            'app' => $app->name,
        ];

        if ($instance instanceof AppInstance) {
            $target['app_instance'] = $instance->name;
            $target['target'] = $this->appRuntimeContainerRenderer()->targetName($app, $instance);
        }

        return [
            ...$target,
            ...$detail,
        ];
    }

    private function containerNameForTarget(Project $canonicalApp, Project $runtimeApp, ?AppInstance $instance): string
    {
        if ($instance instanceof AppInstance) {
            return $this->appRuntimeContainerRenderer()->containerNameForInstance($canonicalApp, $instance);
        }

        return $this->appRuntimeContainerRenderer()->containerName($runtimeApp);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkProductionSecurity(Project $app, ProbeSnapshot $snapshot): array
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
    private function checkOwnerNode(Project $app): array
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
    private function checkSourcePath(
        Project $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?Project $canonicalApp = null,
        ?AppInstance $instance = null,
    ): array {
        $targetName ??= $app->name;
        $canonicalApp ??= $app;
        $observed = $snapshot->get($targetName);

        if ($observed === null) {
            return [];
        }

        if (($observed['path_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.path_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$targetName} path is missing on the owning app node.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'expected' => $app->path,
                    ]),
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDocumentRoot(
        Project $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?Project $canonicalApp = null,
        ?AppInstance $instance = null,
    ): array {
        $targetName ??= $app->name;
        $canonicalApp ??= $app;

        if ($app->path === '' || $app->document_root === '') {
            return [];
        }

        if ($this->documentRootEscapesPath($app)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_outside_path',
                    kind: DriftKind::Divergent,
                    summary: "App {$targetName} document root resolves outside the app path.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'path' => $app->path,
                        'document_root' => $app->document_root,
                    ]),
                ),
            ];
        }

        $observed = $snapshot->get($targetName);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['root_inside_path'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_outside_path',
                    kind: DriftKind::Divergent,
                    summary: "App {$targetName} document root resolves outside the app path.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'path' => $app->path,
                        'document_root' => $app->document_root,
                    ]),
                ),
            ];
        }

        if (($observed['root_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$targetName} document root is missing on the owning app node.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'expected' => $app->documentRootPath(),
                    ]),
                ),
            ];
        }

        return [];
    }

    private function documentRootEscapesPath(Project $app): bool
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
    private function checkAgentIdeDefault(Project $app, AppInstance $instance): array
    {
        $config = $instance->agent_ide_config ?? [];

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
                    summary: "Instance agent IDE adapter for {$app->name}.{$instance->name} is not supported.",
                    detail: [
                        'project' => $app->name,
                        'instance' => $instance->name,
                    ],
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

    private function isProductionApp(Project $app): bool
    {
        $app->loadMissing('node');

        return (
            $app->environment === 'production'
            || $app->node instanceof Node
            && $this->nodeRoleAssignments()->nodeHasActiveRole($app->node, 'app-prod')
        );
    }
}
