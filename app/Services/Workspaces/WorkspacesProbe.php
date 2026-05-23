<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpFpmSystemdHardening;
use App\Services\Php\PhpRuntimeCatalog;

final readonly class WorkspacesProbe
{
    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?WorkspaceFpmPoolRenderer $fpmPoolRenderer = null,
        private ?PhpFpmSystemdHardening $fpmSystemdHardening = null,
        private ?NodeRoleAssignments $nodeRoleAssignments = null,
        private ?PhpRuntimeCatalog $phpRuntimeCatalog = null,
    ) {}

    public function key(): string
    {
        return 'workspace';
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
            'runtime_user' => $this->fpmPoolRenderer()->runtimeUser($workspace),
            'fpm_pool_path' => $this->fpmPoolRenderer()->path($workspace),
            'fpm_pool_hash' => hash('sha256', $this->fpmPoolRenderer()->content($workspace)),
            'fpm_hardening_path' => $this->fpmSystemdHardening()->path((string) $workspace->effectivePhpVersion()),
            'fpm_hardening_hash' => hash('sha256', $this->fpmSystemdHardening()->contentForNode($workspace->app->node, (string) $workspace->effectivePhpVersion())),
        ];

        $php = <<<'PHP'
$spec = json_decode(stream_get_contents(STDIN), true);
$name = (string) ($spec['name'] ?? '');
$path = (string) ($spec['path'] ?? '');
$runtimeUser = (string) ($spec['runtime_user'] ?? '');
$phpVersion = (string) ($spec['php_version'] ?? '');
$fpmPoolPath = (string) ($spec['fpm_pool_path'] ?? '');
$fpmPoolHash = (string) ($spec['fpm_pool_hash'] ?? '');
$fpmHardeningPath = (string) ($spec['fpm_hardening_path'] ?? '');
$fpmHardeningHash = (string) ($spec['fpm_hardening_hash'] ?? '');

$pathExists = is_dir($path) ? '1' : '0';
$pathUsable = is_dir($path) && is_readable($path) && is_executable($path) ? '1' : '0';
$systemUserExists = $runtimeUser !== '' && command_succeeds('id -u '.escapeshellarg($runtimeUser)) ? '1' : '0';
$fsPermissionsOk = $pathExists === '1' && path_owned_by($path, $runtimeUser) && ! group_or_other_writable($path) ? '1' : '0';
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
$fpmHardeningExists = is_file($fpmHardeningPath) ? '1' : '0';
$fpmHardeningMatches = $fpmHardeningExists === '1' && hash_file('sha256', $fpmHardeningPath) === $fpmHardeningHash ? '1' : '0';

printf(
    "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
    $name,
    $pathExists,
    $pathUsable,
    $phpFpmAvailable,
    $fpmConfigExists,
    $fpmConfigMatches,
    $systemUserExists,
    $fsPermissionsOk,
    $fpmHardeningExists,
    $fpmHardeningMatches
);

function command_exists(string $command): bool
{
    $escapedCommand = escapeshellarg($command);

    exec("command -v {$escapedCommand} >/dev/null 2>&1", $output, $exitCode);

    return $exitCode === 0;
}

function command_succeeds(string $command): bool
{
    exec($command.' >/dev/null 2>&1', $output, $exitCode);

    return $exitCode === 0;
}

function path_owned_by(string $path, string $user): bool
{
    if ($user === '' || ! file_exists($path) || ! function_exists('posix_getpwuid')) {
        return false;
    }

    $owner = posix_getpwuid(fileowner($path));

    return is_array($owner) && ($owner['name'] ?? null) === $user;
}

function group_or_other_writable(string $path): bool
{
    if (! file_exists($path)) {
        return true;
    }

    return (fileperms($path) & 0022) !== 0;
}
PHP;

        $script = 'set -euo pipefail'.PHP_EOL.'php -r '.escapeshellarg($php);

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($workspace->app->node, $script, [
            'throw' => true,
            'input' => (string) json_encode($spec, JSON_THROW_ON_ERROR),
        ]);

        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) !== 6 && count($parts) !== 10) {
                continue;
            }

            [$name, $pathExists, $pathUsable, $phpFpmAvailable, $fpmConfigExists, $fpmConfigMatches] = array_slice($parts, 0, 6);

            $items[$name] = [
                'path_exists' => $pathExists === '1',
                'path_usable' => $pathUsable === '1',
                'php_fpm_available' => $phpFpmAvailable === '1',
                'fpm_config_exists' => $fpmConfigExists === '1',
                'fpm_config_matches' => $fpmConfigMatches === '1',
            ];

            if (count($parts) === 10) {
                $items[$name] = [
                    ...$items[$name],
                    'system_user_exists' => $parts[6] === '1',
                    'fs_permissions_ok' => $parts[7] === '1',
                    'fpm_hardening_exists' => $parts[8] === '1',
                    'fpm_hardening_matches' => $parts[9] === '1',
                ];
            }
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
        $drift = array_merge($drift, $this->checkFpmConfig($workspace, $snapshot));
        $drift = array_merge($drift, $this->checkDevelopmentSecurity($workspace, $snapshot));

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
    private function checkFpmConfig(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        $workspace->loadMissing('app');

        if ($workspace->app?->runtime_kind === AppRuntimeKind::Php) {
            return [];
        }

        $observed = $snapshot->get($workspace->name);

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
                    key: 'workspace.fpm_config_missing',
                    kind: DriftKind::Missing,
                    summary: "Workspace {$workspace->name} PHP-FPM configuration is missing.",
                    detail: [
                        'expected' => $this->fpmPoolRenderer()->path($workspace),
                    ],
                ),
            ];
        }

        if (($observed['fpm_config_matches'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.fpm_config_mismatch',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} PHP-FPM configuration differs from gateway workspace intent.",
                    detail: [
                        'expected' => $this->fpmPoolRenderer()->path($workspace),
                    ],
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
        $workspace->loadMissing('app');

        if (! $workspace->app instanceof App || $workspace->app->runtime_kind !== AppRuntimeKind::Php) {
            return [];
        }

        if (! $this->phpRuntimeCatalog()->supports($workspace->effectivePhpVersion())) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "PHP {$workspace->effectivePhpVersion()} is not a supported FrankenPHP runtime image for workspace {$workspace->name}.",
                    detail: [
                        'php_version' => $workspace->effectivePhpVersion(),
                    ],
                ),
            ];
        }

        $observed = $snapshot->get($workspace->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['docker_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "Docker is not available to serve PHP {$workspace->effectivePhpVersion()} for workspace {$workspace->name} on the parent app node.",
                    detail: [
                        'php_version' => $workspace->effectivePhpVersion(),
                    ],
                ),
            ];
        }

        if (($observed['runtime_image_probe_failed'] ?? null) === true) {
            return [];
        }

        if (($observed['runtime_image_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "FrankenPHP runtime image for PHP {$workspace->effectivePhpVersion()} is not available on the parent app node for workspace {$workspace->name}.",
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
    private function checkDevelopmentSecurity(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        $workspace->loadMissing('app.node');

        if (! $workspace->app instanceof App || ! $workspace->app->node instanceof Node) {
            return [];
        }

        if ($this->nodeRoleAssignments()->nodeHasActiveRole($workspace->app->node, 'app-production')) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.unsupported_for_production',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} belongs to a production app role where workspaces are disabled.",
                    detail: [
                        'workspace' => $workspace->name,
                        'app' => $workspace->app->name,
                        'node' => $workspace->app->node->name,
                    ],
                ),
            ];
        }

        if (! $this->nodeRoleAssignments()->nodeHasActiveRole($workspace->app->node, 'app-development')) {
            return [];
        }

        $observed = $snapshot->get($workspace->name);

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
                key: 'workspace.security.system_user',
                kind: DriftKind::Missing,
                summary: "Workspace {$workspace->name} is missing its expected runtime user.",
                detail: [
                    'workspace' => $workspace->name,
                    'runtime_user' => $this->fpmPoolRenderer()->runtimeUser($workspace),
                ],
            );
        }

        if (($observed['fs_permissions_ok'] ?? null) === false) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'workspace.security.fs_permissions',
                kind: DriftKind::Divergent,
                summary: "Workspace {$workspace->name} filesystem permissions do not match runtime policy.",
                detail: [
                    'workspace' => $workspace->name,
                    'path' => $workspace->path,
                    'runtime_user' => $this->fpmPoolRenderer()->runtimeUser($workspace),
                ],
            );
        }

        if ($workspace->app?->runtime_kind !== AppRuntimeKind::Php) {
            if (
                ($observed['php_fpm_available'] ?? null) === true
                && (
                    ($observed['fpm_config_exists'] ?? null) === false
                    || ($observed['fpm_config_matches'] ?? null) === false
                )
            ) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.security.fpm_pool_isolation',
                    kind: $observed['fpm_config_exists'] === false ? DriftKind::Missing : DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} PHP-FPM pool isolation does not match security policy.",
                    detail: [
                        'workspace' => $workspace->name,
                        'expected' => $this->fpmPoolRenderer()->path($workspace),
                    ],
                );
            }

            if (
                ($observed['php_fpm_available'] ?? null) === true
                && (
                    ($observed['fpm_hardening_exists'] ?? null) === false
                    || ($observed['fpm_hardening_matches'] ?? null) === false
                )
            ) {
                $drift[] = new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.security.fpm_systemd_hardening',
                    kind: $observed['fpm_hardening_exists'] === false ? DriftKind::Missing : DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} PHP-FPM service hardening is missing or divergent.",
                    detail: [
                        'workspace' => $workspace->name,
                        'expected' => $this->fpmSystemdHardening()->path((string) $workspace->effectivePhpVersion()),
                    ],
                );
            }
        }

        return $drift;
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
            || $workspace->app->node->status !== 'active'
            || ! $this->nodeRoleAssignments()->nodeHasActiveAppHostRole($workspace->app->node)
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

        if ($this->violatesGenericWorktreePolicy($workspace)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.path_outside_policy',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} path is outside the generic worktree policy.",
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

    private function violatesGenericWorktreePolicy(Workspace $workspace): bool
    {
        $workspace->loadMissing('app');

        if ($workspace->agent_ide !== null && $workspace->agent_ide !== 'none') {
            return false;
        }

        if (! $workspace->app instanceof App || $workspace->app->path === '') {
            return false;
        }

        $appPath = $this->normalizePath($workspace->app->path);
        $workspacePath = $this->normalizePath($workspace->path);

        return ! str_starts_with($workspacePath, "{$appPath}/.worktrees/");
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

    private function fpmPoolRenderer(): WorkspaceFpmPoolRenderer
    {
        return $this->fpmPoolRenderer ?? app(WorkspaceFpmPoolRenderer::class);
    }

    private function fpmSystemdHardening(): PhpFpmSystemdHardening
    {
        return $this->fpmSystemdHardening ?? app(PhpFpmSystemdHardening::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function phpRuntimeCatalog(): PhpRuntimeCatalog
    {
        return $this->phpRuntimeCatalog ?? app(PhpRuntimeCatalog::class);
    }
}
