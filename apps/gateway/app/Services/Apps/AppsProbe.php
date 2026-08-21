<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\InstanceDriver;
use App\Enums\Apps\NodeRuntimeConfigsProbeStatus;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
use App\Services\Workspaces\WorkspacePlacement;
use Throwable;

final readonly class AppsProbe
{
    public function __construct(
        private ?AppRuntimeUser $appRuntimeUser = null,
        private ?AppRuntimeContainerRenderer $appRuntimeContainerRenderer = null,
        private ?PhpRuntimeCatalog $phpRuntimeCatalog = null,
        private ?NodeRoleAssignments $nodeRoleAssignments = null,
        private ?RemoteAppIntrospectProbe $introspectProbe = null,
        private ?RemoteAppRuntimeConfigsProbe $runtimeConfigsProbe = null,
        private ?WorkspacePlacement $placement = null,
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
        $node = $this->placement()->runtimeNode($app, null);

        if (! $node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        return $this->snapshotFromProbeOrUnverifiable(
            node: $node,
            payload: $this->introspectionPayload($app),
            fallbackName: $app->name,
        );
    }

    public function introspectInstance(App $app, Instance $instance): ProbeSnapshot
    {
        $node = $this->placement()->runtimeNode($app, $instance);

        if (! $node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        return $this->snapshotFromProbeOrUnverifiable(
            node: $node,
            payload: $this->introspectionPayload($app, $instance),
            fallbackName: $this->appRuntimeContainerRenderer()->targetName($app, $instance),
        );
    }

    /**
     * @return array<string, string>
     */
    private function introspectionPayload(App $app, ?Instance $instance = null): array
    {
        $renderer = $this->appRuntimeContainerRenderer();
        $isPhpApp = $app->runtimeKind() === AppRuntimeKind::Php;
        $containerName = '';

        if ($isPhpApp) {
            $containerName = $instance instanceof Instance
                ? $renderer->containerNameForInstance($app, $instance)
                : $renderer->containerName($app);
        }
        $expectedSpecHash = '';
        $expectedRuntimeConfigHash = '';
        $runtimeConfigPath = '';
        $expectedImage = '';

        if ($isPhpApp) {
            try {
                $renderedContainer = $instance instanceof Instance
                    ? $renderer->renderForInstance($app, $instance)
                    : $renderer->render($app);
                $expectedSpecHash = $renderedContainer->specHash();
                $expectedRuntimeConfigHash = hash('sha256', $renderedContainer->phpIniContent());
                $runtimeConfigPath = $instance instanceof Instance
                    ? $renderer->phpIniHostPathForInstance($app, $instance)
                    : $renderer->phpIniHostPath($app);
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
            'path' => rtrim($this->placement()->runtimePath($app, $instance), '/'),
            'document_root' => $this->placement()->runtimeDocumentRoot($app, $instance),
            'runtime_kind' => $app->runtimeKind()->value,
            'runtime_user' => $this->appRuntimeUser()->forApp($app, $instance),
            'runtime_container_name' => $containerName,
            'expected_spec_hash' => $expectedSpecHash,
            'runtime_config_path' => $runtimeConfigPath,
            'expected_runtime_config_hash' => $expectedRuntimeConfigHash,
            'expected_runtime_image' => $expectedImage,
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function snapshotFromProbeOrUnverifiable(Node $node, array $payload, string $fallbackName): ProbeSnapshot
    {
        try {
            return $this->snapshotFromProbe(
                snapshot: $this->introspectProbe()->snapshot($node, $payload),
                fallbackName: $fallbackName,
            );
        } catch (RemoteShellProtocolException) {
            return new ProbeSnapshot([
                $fallbackName => [
                    'probe_unverifiable' => true,
                ],
            ]);
        }
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
     * The whole-app view: audits every concrete Orbit instance on its own
     * serving node. App owns no placement to check on its own. `$snapshot` is an
     * aggregate keyed by each instance's target name (app.instance) — each
     * instance is evaluated only against its own snapshot entry, never a shared
     * app-level entry. An app with no concrete Orbit instance is an incomplete
     * record.
     *
     * @return list<DriftEntry>
     */
    public function diff(App $app, ProbeSnapshot $snapshot): array
    {
        $app->loadMissing('instances');
        $orbitInstances = $app
            ->instances
            ->filter(static fn (Instance $instance): bool => $instance->driver === InstanceDriver::Orbit)
            ->values();

        if ($orbitInstances->isEmpty()) {
            return $this->checkRecordCompleteness($app, null);
        }

        $drift = [];

        foreach ($orbitInstances as $instance) {
            assert($instance instanceof Instance);
            $drift = array_merge($drift, $this->diffInstance($app, $instance, $snapshot));
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    public function diffInstance(App $app, Instance $instance, ProbeSnapshot $snapshot): array
    {
        $targetName = $this->appRuntimeContainerRenderer()->targetName($app, $instance);
        $observed = $snapshot->get($targetName);

        if (($observed['probe_unverifiable'] ?? null) === true) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.remote_shell_probe_failed',
                    kind: DriftKind::Unverifiable,
                    summary: "App {$targetName} remote introspect envelope was invalid.",
                    detail: $this->targetDetail($app, $instance),
                ),
            ];
        }

        $drift = [];
        $drift = array_merge($drift, $this->checkRecordCompleteness($app, $instance));
        $drift = array_merge($drift, $this->checkOwnerNode($app, $instance));
        $drift = array_merge($drift, $this->checkSourcePath($app, $snapshot, $targetName, $app, $instance));
        $drift = array_merge($drift, $this->checkDocumentRoot($app, $snapshot, $targetName, $app, $instance));
        $drift = array_merge($drift, $this->checkPhpRuntime($app, $snapshot, $targetName, $app, $instance));
        $drift = array_merge($drift, $this->checkRuntimeConfig($app, $snapshot, $targetName, $app, $instance));
        $drift = array_merge($drift, $this->checkProductionSecurity($app, $snapshot, $targetName, $instance));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(App $app, ?Instance $instance = null): array
    {
        // Placement is instance-authoritative: a complete record is an app with
        // a logical identity (name + php_version) plus a concrete instance whose
        // placement resolves. For Orbit instances that means a resolvable node,
        // source path, and document root. Evaluated per instance.
        $incomplete =
            ! is_string($app->name)
            || $app->name === ''
            || ! is_string($app->php_version)
            || $app->php_version === ''
            || ! $instance instanceof Instance;

        if (! $incomplete && $instance instanceof Instance && $instance->driver === InstanceDriver::Orbit) {
            $incomplete =
                ! $this->placement()->runtimeNode($app, $instance) instanceof Node
                || $this->placement()->runtimePath($app, $instance) === ''
                || $this->placement()->runtimeDocumentRoot($app, $instance) === '';
        }

        if ($incomplete) {
            $label = $instance instanceof Instance
                ? $this->appRuntimeContainerRenderer()->targetName($app, $instance)
                : $app->name;

            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "App record for {$label} is missing required fields.",
                    detail: $this->targetDetail($app, $instance),
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeConfig(
        App $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?App $canonicalApp = null,
        ?Instance $instance = null,
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

        $expectedPath = $instance instanceof Instance
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
        App $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?App $canonicalApp = null,
        ?Instance $instance = null,
    ): array {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return [];
        }

        $phpVersion = $this->placement()->runtimePhpVersion($app, $instance);

        if (! $this->phpRuntimeCatalog()->supports($phpVersion)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "PHP {$phpVersion} is not a supported FrankenPHP runtime image for app {$app->name}.",
                    detail: [
                        'php_version' => $phpVersion,
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
                    summary: "Docker is not available to serve PHP {$phpVersion} for app {$app->name} on the owning app node.",
                    detail: [
                        'php_version' => $phpVersion,
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
            $expectedImage = $this->expectedImageOrEmpty($app, $instance);

            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "FrankenPHP runtime image for PHP {$phpVersion} is not available on the owning app node for app {$targetName}.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'php_version' => $phpVersion,
                        'expected_image' => $expectedImage,
                    ]),
                ),
            ];
        }

        return [];
    }

    private function expectedImageOrEmpty(App $app, ?Instance $instance = null): string
    {
        try {
            $renderer = $this->appRuntimeContainerRenderer();
            $container = $instance instanceof Instance
                ? $renderer->renderForInstance($app, $instance)
                : $renderer->render($app);

            return $container->image();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function targetDetail(App $app, ?Instance $instance, array $detail = []): array
    {
        $target = [
            'app' => $app->name,
        ];

        if ($instance instanceof Instance) {
            $target['instance'] = $instance->name;
            $target['target'] = $this->appRuntimeContainerRenderer()->targetName($app, $instance);
        }

        return [
            ...$target,
            ...$detail,
        ];
    }

    private function containerNameForTarget(App $canonicalApp, App $runtimeApp, ?Instance $instance): string
    {
        if ($instance instanceof Instance) {
            return $this->appRuntimeContainerRenderer()->containerNameForInstance($canonicalApp, $instance);
        }

        return $this->appRuntimeContainerRenderer()->containerName($runtimeApp);
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkProductionSecurity(
        App $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?Instance $instance = null,
    ): array {
        if (! $this->isProductionInstance($app, $instance)) {
            return [];
        }

        $targetName ??= $app->name;
        $observed = $snapshot->get($targetName);

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
                summary: "Production app {$targetName} is missing its expected runtime user.",
                detail: $this->targetDetail($app, $instance, [
                    'runtime_user' => $this->appRuntimeUser()->forApp($app, $instance),
                ]),
            );
        }

        if (($observed['fs_permissions_ok'] ?? null) === false) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'app.security.fs_permissions',
                kind: DriftKind::Divergent,
                summary: "Production app {$targetName} filesystem permissions do not match runtime policy.",
                detail: $this->targetDetail($app, $instance, [
                    'path' => $this->placement()->runtimePath($app, $instance),
                    'runtime_user' => $this->appRuntimeUser()->forApp($app, $instance),
                ]),
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
                summary: "Production app {$targetName} runtime container isolation does not match security policy.",
                detail: $this->targetDetail($app, $instance, [
                    'expected' => $this->containerNameForTarget($app, $app, $instance),
                ]),
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkOwnerNode(App $app, ?Instance $instance = null): array
    {
        $node = $this->placement()->runtimeNode($app, $instance);
        $label = $instance instanceof Instance
            ? $this->appRuntimeContainerRenderer()->targetName($app, $instance)
            : $app->name;

        if (! $node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.serving_node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "App {$label} points at a missing owning node.",
                    detail: $this->targetDetail($app, $instance),
                ),
            ];
        }

        $requiredRole = $this->placement()->runtimeEnvironment($app, $instance) === 'production'
            ? 'app-prod'
            : 'app-dev';

        if (! $node->isActive() || ! $this->nodeRoleAssignments()->nodeHasActiveRole($node, $requiredRole)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.serving_node_invalid',
                    kind: DriftKind::Divergent,
                    summary: "App {$label} is owned by node {$node->name}, which is not an active app node.",
                    detail: $this->targetDetail($app, $instance),
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkSourcePath(
        App $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?App $canonicalApp = null,
        ?Instance $instance = null,
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
                        'expected' => $this->placement()->runtimePath($app, $instance),
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
        App $app,
        ProbeSnapshot $snapshot,
        ?string $targetName = null,
        ?App $canonicalApp = null,
        ?Instance $instance = null,
    ): array {
        $targetName ??= $app->name;
        $canonicalApp ??= $app;
        $path = $this->placement()->runtimePath($app, $instance);
        $documentRoot = $this->placement()->runtimeDocumentRoot($app, $instance);

        if ($path === '' || $documentRoot === '') {
            return [];
        }

        if ($this->documentRootEscapesPath($app, $instance)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.root_outside_path',
                    kind: DriftKind::Divergent,
                    summary: "App {$targetName} document root resolves outside the app path.",
                    detail: $this->targetDetail($canonicalApp, $instance, [
                        'path' => $path,
                        'document_root' => $documentRoot,
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
                        'path' => $path,
                        'document_root' => $documentRoot,
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
                        'expected' => $this->placement()->runtimeDocumentRootPath($app, $instance),
                    ]),
                ),
            ];
        }

        return [];
    }

    private function documentRootEscapesPath(App $app, ?Instance $instance = null): bool
    {
        $appPath = $this->placement()->runtimePath($app, $instance);
        $path = $this->normalizePath($appPath);
        $root = trim($this->placement()->runtimeDocumentRoot($app, $instance), '/');
        $rootPath = $root === '' ? $path : $this->normalizePath("{$appPath}/{$root}");

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

    private function appRuntimeUser(): AppRuntimeUser
    {
        return $this->appRuntimeUser ?? app(AppRuntimeUser::class);
    }

    private function appRuntimeContainerRenderer(): AppRuntimeContainerRenderer
    {
        return $this->appRuntimeContainerRenderer ?? app(AppRuntimeContainerRenderer::class);
    }

    private function placement(): WorkspacePlacement
    {
        return $this->placement ?? app(WorkspacePlacement::class);
    }

    private function phpRuntimeCatalog(): PhpRuntimeCatalog
    {
        return $this->phpRuntimeCatalog ?? app(PhpRuntimeCatalog::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function isProductionInstance(App $app, ?Instance $instance = null): bool
    {
        if ($this->placement()->runtimeEnvironment($app, $instance) === 'production') {
            return true;
        }

        $node = $this->placement()->runtimeNode($app, $instance);

        return $node instanceof Node && $this->nodeRoleAssignments()->nodeHasActiveRole($node, 'app-prod');
    }
}
