<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpFpmSystemdHardening;

final readonly class AppsProbe
{
    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?AppFpmPoolRenderer $fpmPoolRenderer = null,
        private ?PhpFpmSystemdHardening $fpmSystemdHardening = null,
        private ?AppAgentIdeDefaults $agentIdeDefaults = null,
        private ?NodeRoleAssignments $nodeRoleAssignments = null,
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
            'runtime_user' => $this->fpmPoolRenderer()->runtimeUser($app),
            'fpm_pool_path' => $this->fpmPoolPath($app),
            'fpm_pool_hash' => hash('sha256', $this->fpmPoolRenderer()->content($app)),
            'fpm_hardening_path' => $this->fpmSystemdHardening()->path($app->php_version),
            'fpm_hardening_hash' => hash('sha256', $this->fpmSystemdHardening()->contentForNode($app->node, $app->php_version)),
        ];

        $php = <<<'PHP'
$spec = json_decode(stream_get_contents(STDIN), true);
$name = (string) ($spec['name'] ?? '');
$path = rtrim((string) ($spec['path'] ?? ''), '/');
$documentRoot = (string) ($spec['document_root'] ?? '');
$runtimeUser = (string) ($spec['runtime_user'] ?? '');

$pathExists = is_dir($path) ? '1' : '0';
$root = trim($documentRoot, '/');
$rootPath = $root === '' ? $path : $path.'/'.$root;

$rootExists = is_dir($rootPath) ? '1' : '0';
$rootInsidePath = str_starts_with(normalize_path($rootPath), normalize_path($path).'/') || normalize_path($rootPath) === normalize_path($path) ? '1' : '0';
$systemUserExists = $runtimeUser !== '' && command_succeeds('id -u '.escapeshellarg($runtimeUser)) ? '1' : '0';
$fsPermissionsOk = $pathExists === '1' && path_owned_by($path, $runtimeUser) && ! group_or_other_writable($path) ? '1' : '0';
$phpVersion = (string) ($spec['php_version'] ?? '');
$fpmPoolPath = (string) ($spec['fpm_pool_path'] ?? '');
$fpmPoolHash = (string) ($spec['fpm_pool_hash'] ?? '');
$fpmHardeningPath = (string) ($spec['fpm_hardening_path'] ?? '');
$fpmHardeningHash = (string) ($spec['fpm_hardening_hash'] ?? '');
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
    "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
    $name,
    $pathExists,
    $rootExists,
    $rootInsidePath,
    $phpFpmAvailable,
    $fpmConfigExists,
    $fpmConfigMatches,
    $systemUserExists,
    $fsPermissionsOk,
    $fpmHardeningExists,
    $fpmHardeningMatches
);

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

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($app->node, $script, [
            'throw' => true,
            'input' => (string) json_encode($spec, JSON_THROW_ON_ERROR),
        ]);

        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) !== 7 && count($parts) !== 11) {
                continue;
            }

            [
                $name,
                $pathExists,
                $rootExists,
                $rootInsidePath,
                $phpFpmAvailable,
                $fpmConfigExists,
                $fpmConfigMatches,
            ] = array_slice($parts, 0, 7);

            $items[$name] = [
                'path_exists' => $pathExists === '1',
                'root_exists' => $rootExists === '1',
                'root_inside_path' => $rootInsidePath === '1',
                'php_fpm_available' => $phpFpmAvailable === '1',
                'fpm_config_exists' => $fpmConfigExists === '1',
                'fpm_config_matches' => $fpmConfigMatches === '1',
            ];

            if (count($parts) === 11) {
                $items[$name] = [
                    ...$items[$name],
                    'system_user_exists' => $parts[7] === '1',
                    'fs_permissions_ok' => $parts[8] === '1',
                    'fpm_hardening_exists' => $parts[9] === '1',
                    'fpm_hardening_matches' => $parts[10] === '1',
                ];
            }
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
                    'runtime_user' => $this->fpmPoolRenderer()->runtimeUser($app),
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
                    'runtime_user' => $this->fpmPoolRenderer()->runtimeUser($app),
                ],
            );
        }

        if (
            ($observed['php_fpm_available'] ?? null) === true
            && (
                ($observed['fpm_config_exists'] ?? null) === false
                || ($observed['fpm_config_matches'] ?? null) === false
            )
        ) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'app.security.fpm_pool_isolation',
                kind: $observed['fpm_config_exists'] === false ? DriftKind::Missing : DriftKind::Divergent,
                summary: "Production app {$app->name} PHP-FPM pool isolation does not match security policy.",
                detail: [
                    'app' => $app->name,
                    'expected' => $this->fpmPoolPath($app),
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
                key: 'app.security.fpm_systemd_hardening',
                kind: $observed['fpm_hardening_exists'] === false ? DriftKind::Missing : DriftKind::Divergent,
                summary: "Production app {$app->name} PHP-FPM service hardening is missing or divergent.",
                detail: [
                    'app' => $app->name,
                    'expected' => $this->fpmSystemdHardening()->path($app->php_version),
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

        $requiredRole = $app->environment === 'production' ? 'app-production' : 'app-development';

        if ($app->node->status !== 'active' || ! $this->nodeRoleAssignments()->nodeHasActiveRole($app->node, $requiredRole)) {
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

    private function fpmSystemdHardening(): PhpFpmSystemdHardening
    {
        return $this->fpmSystemdHardening ?? app(PhpFpmSystemdHardening::class);
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

        return $app->environment === 'production'
            || ($app->node instanceof Node && $this->nodeRoleAssignments()->nodeHasActiveRole($app->node, 'app-production'));
    }
}
