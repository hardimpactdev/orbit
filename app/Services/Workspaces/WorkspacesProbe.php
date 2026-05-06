<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;

final readonly class WorkspacesProbe
{
    public function __construct(
        private ?RemoteShell $remoteShell = null,
    ) {}

    public function key(): string
    {
        return 'workspaces';
    }

    public function label(): string
    {
        return 'Workspaces';
    }

    public function introspect(Workspace $workspace): ProbeSnapshot
    {
        $workspace->loadMissing('app.node');

        if (! $workspace->app instanceof App || ! $workspace->app->node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $spec = [
            'name' => $workspace->name,
            'path' => $workspace->path,
            'php_version' => $workspace->effectivePhpVersion(),
        ];

        $script = <<<'BASH'
set -euo pipefail
php <<'PHP'
<?php
$spec = json_decode(base64_decode((string) getenv('ORBIT_WORKSPACE_SPEC')), true);
$name = (string) ($spec['name'] ?? '');
$path = (string) ($spec['path'] ?? '');
$phpVersion = (string) ($spec['php_version'] ?? '');

$pathExists = is_dir($path) ? '1' : '0';
$pathUsable = is_dir($path) && is_readable($path) && is_executable($path) ? '1' : '0';
$phpFpmAvailable = (
    is_executable("/usr/sbin/php-fpm{$phpVersion}")
    || command_exists("php-fpm{$phpVersion}")
    || command_exists("php{$phpVersion}-fpm")
    || command_exists('php-fpm')
)
        ? '1'
        : '0';

printf("%s\t%s\t%s\t%s\n", $name, $pathExists, $pathUsable, $phpFpmAvailable);

function command_exists(string $command): bool
{
    $escapedCommand = escapeshellarg($command);

    exec("command -v {$escapedCommand} >/dev/null 2>&1", $output, $exitCode);

    return $exitCode === 0;
}
PHP
BASH;

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($workspace->app->node, $script, [
            'throw' => true,
            'env' => ['ORBIT_WORKSPACE_SPEC' => base64_encode((string) json_encode($spec))],
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

            [$name, $pathExists, $pathUsable, $phpFpmAvailable] = $parts;

            $items[$name] = [
                'path_exists' => $pathExists === '1',
                'path_usable' => $pathUsable === '1',
                'php_fpm_available' => $phpFpmAvailable === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($workspace));
        $drift = array_merge($drift, $this->checkParentApp($workspace));
        $drift = array_merge($drift, $this->checkSourcePath($workspace, $snapshot));
        $drift = array_merge($drift, $this->checkPhpRuntime($workspace, $snapshot));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Workspace $workspace): array
    {
        if (
            ! is_string($workspace->name)
            || $workspace->name === ''
            || ! is_int($workspace->app_id)
            || ! is_string($workspace->path)
            || $workspace->path === ''
            || ! is_string($workspace->effectivePhpVersion())
            || $workspace->effectivePhpVersion() === ''
            || ! is_string($workspace->getRawOriginal('lifecycle_status'))
            || $workspace->getRawOriginal('lifecycle_status') === ''
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Workspace record for {$workspace->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkPhpRuntime(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($workspace->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['php_fpm_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "PHP {$workspace->effectivePhpVersion()} FPM is not available for workspace {$workspace->name} on the parent app node.",
                    detail: [
                        'php_version' => $workspace->effectivePhpVersion(),
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkParentApp(Workspace $workspace): array
    {
        $workspace->loadMissing('app.node');

        if (! $workspace->app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.parent_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} points at a missing parent app.",
                ),
            ];
        }

        if (
            ! $workspace->app->node instanceof Node
            || $workspace->app->node->role !== 'app'
            || $workspace->app->node->status !== 'active'
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.parent_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} parent app {$workspace->app->name} is not on an active app node.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkSourcePath(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        if ($workspace->path === '') {
            return [];
        }

        if ($this->pathEscapesParentApp($workspace)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.path_outside_policy',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} path is outside the parent app path.",
                    detail: [
                        'path' => $workspace->path,
                        'app_path' => $workspace->app?->path,
                    ],
                ),
            ];
        }

        $observed = $snapshot->get($workspace->name);

        if ($observed === null) {
            return [];
        }

        if (($observed['path_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.path_missing',
                    kind: DriftKind::Missing,
                    summary: "Workspace {$workspace->name} path is missing on the parent app node.",
                    detail: [
                        'expected' => $workspace->path,
                    ],
                ),
            ];
        }

        if (($observed['path_usable'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.path_unusable',
                    kind: DriftKind::Unverifiable,
                    summary: "Workspace {$workspace->name} path exists but is not usable by Orbit.",
                    detail: [
                        'path' => $workspace->path,
                    ],
                ),
            ];
        }

        return [];
    }

    private function pathEscapesParentApp(Workspace $workspace): bool
    {
        $workspace->loadMissing('app');

        if (! $workspace->app instanceof App || $workspace->app->path === '') {
            return false;
        }

        $appPath = $this->normalizePath($workspace->app->path);
        $workspacePath = $this->normalizePath($workspace->path);

        return $workspacePath !== $appPath && ! str_starts_with($workspacePath, "{$appPath}/");
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
}
