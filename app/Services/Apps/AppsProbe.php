<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;

final readonly class AppsProbe
{
    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?AppFpmPoolRenderer $fpmPoolRenderer = null,
        private ?AppAgentIdeDefaults $agentIdeDefaults = null,
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

        $spec = [
            'name' => $app->name,
            'path' => $app->path,
            'document_root' => $app->document_root,
            'php_version' => $app->php_version,
            'fpm_pool_path' => $this->fpmPoolPath($app),
            'fpm_pool_hash' => hash('sha256', $this->fpmPoolRenderer()->content($app)),
        ];

        $script = <<<'BASH'
set -euo pipefail
php <<'PHP'
<?php
$spec = json_decode(base64_decode((string) getenv('ORBIT_APP_SPEC')), true);
$name = (string) ($spec['name'] ?? '');
$path = rtrim((string) ($spec['path'] ?? ''), '/');
$documentRoot = (string) ($spec['document_root'] ?? '');

$pathExists = is_dir($path) ? '1' : '0';
$root = trim($documentRoot, '/');
$rootPath = $root === '' ? $path : $path.'/'.$root;

$rootExists = is_dir($rootPath) ? '1' : '0';
$rootInsidePath = str_starts_with(normalize_path($rootPath), normalize_path($path).'/') || normalize_path($rootPath) === normalize_path($path) ? '1' : '0';
$phpVersion = (string) ($spec['php_version'] ?? '');
$fpmPoolPath = (string) ($spec['fpm_pool_path'] ?? '');
$fpmPoolHash = (string) ($spec['fpm_pool_hash'] ?? '');
$phpFpmAvailable = (
    is_executable("/usr/sbin/php-fpm{$phpVersion}")
    || command_exists("php-fpm{$phpVersion}")
    || command_exists("php{$phpVersion}-fpm")
    || command_exists('php-fpm')
)
        ? '1'
        : '0';
$fpmConfigExists = is_file($fpmPoolPath) ? '1' : '0';
$fpmConfigMatches = $fpmConfigExists === '1' && hash_file('sha256', $fpmPoolPath) === $fpmPoolHash ? '1' : '0';

printf("%s\t%s\t%s\t%s\t%s\t%s\t%s\n", $name, $pathExists, $rootExists, $rootInsidePath, $phpFpmAvailable, $fpmConfigExists, $fpmConfigMatches);

function normalize_path(string $path): string
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

function command_exists(string $command): bool
{
    $escapedCommand = escapeshellarg($command);

    exec("command -v {$escapedCommand} >/dev/null 2>&1", $output, $exitCode);

    return $exitCode === 0;
}
PHP
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($app->node, $script, [
            'throw' => true,
            'env' => ['ORBIT_APP_SPEC' => base64_encode((string) json_encode($spec))],
        ]);

        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, 7);

            if (count($parts) !== 7) {
                continue;
            }

            [$name, $pathExists, $rootExists, $rootInsidePath, $phpFpmAvailable, $fpmConfigExists, $fpmConfigMatches] = $parts;

            $items[$name] = [
                'path_exists' => $pathExists === '1',
                'root_exists' => $rootExists === '1',
                'root_inside_path' => $rootInsidePath === '1',
                'php_fpm_available' => $phpFpmAvailable === '1',
                'fpm_config_exists' => $fpmConfigExists === '1',
                'fpm_config_matches' => $fpmConfigMatches === '1',
            ];
        }

        return new ProbeSnapshot($items);
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
        $drift = array_merge($drift, $this->checkFpmConfig($app, $snapshot));
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
    private function checkFpmConfig(App $app, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($app->name);

        if (
            $observed === null
            || ($observed['path_exists'] ?? null) === false
            || ($observed['php_fpm_available'] ?? null) === false
        ) {
            return [];
        }

        if (($observed['fpm_config_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.fpm_config_missing',
                    kind: DriftKind::Missing,
                    summary: "App {$app->name} PHP-FPM configuration is missing.",
                    detail: [
                        'expected' => $this->fpmPoolPath($app),
                    ],
                ),
            ];
        }

        if (($observed['fpm_config_matches'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.fpm_config_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "App {$app->name} PHP-FPM configuration differs from gateway app intent.",
                    detail: [
                        'expected' => $this->fpmPoolPath($app),
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
        $observed = $snapshot->get($app->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['php_fpm_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'app.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "PHP {$app->php_version} FPM is not available for app {$app->name} on the owning app node.",
                    detail: [
                        'php_version' => $app->php_version,
                    ],
                ),
            ];
        }

        return [];
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

        if ($app->node->role !== 'app' || $app->node->status !== 'active') {
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

    private function fpmPoolPath(App $app): string
    {
        return $this->fpmPoolRenderer()->path($app);
    }

    private function fpmPoolRenderer(): AppFpmPoolRenderer
    {
        return $this->fpmPoolRenderer ?? app(AppFpmPoolRenderer::class);
    }

    private function agentIdeDefaults(): AppAgentIdeDefaults
    {
        return $this->agentIdeDefaults ?? app(AppAgentIdeDefaults::class);
    }
}
