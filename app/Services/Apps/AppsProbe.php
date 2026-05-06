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
    private const array SUPPORTED_AGENT_IDE_ADAPTERS = ['none', ...AppAgentIdeDefaults::SUPPORTED_ADAPTERS];

    public function __construct(
        private ?RemoteShell $remoteShell = null,
    ) {}

    public function key(): string
    {
        return 'apps';
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

printf("%s\t%s\t%s\t%s\n", $name, $pathExists, $rootExists, $rootInsidePath);

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

            $parts = explode("\t", $line, 4);

            if (count($parts) !== 4) {
                continue;
            }

            [$name, $pathExists, $rootExists, $rootInsidePath] = $parts;

            $items[$name] = [
                'path_exists' => $pathExists === '1',
                'root_exists' => $rootExists === '1',
                'root_inside_path' => $rootInsidePath === '1',
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

        if (! is_string($adapter) || ! in_array($adapter, self::SUPPORTED_AGENT_IDE_ADAPTERS, true)) {
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
}
