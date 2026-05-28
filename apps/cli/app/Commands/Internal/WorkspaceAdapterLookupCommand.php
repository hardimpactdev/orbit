<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use JsonException;
use PDO;
use PDOException;
use PDOStatement;

final class WorkspaceAdapterLookupCommand extends InternalExecutorCommand
{
    private const string AdapterPolyscope = 'polyscope';

    private const string AdapterOpenCode = 'opencode';

    #[\Override]
    protected $signature = 'internal:workspace-adapter:lookup {--adapter=} {--operation-token=} {--json} {--workspace-path=} {--app-path=} {--lookup=workspace}';

    #[\Override]
    protected $description = 'Look up agent IDE workspace adapter state';

    public function handle(): int
    {
        if (! $this->verifyOperationToken('internal:workspace-adapter:lookup')) {
            return self::FAILURE;
        }

        $adapter = $this->adapterOption();

        if ($adapter === null) {
            return $this->renderFailure(
                'validation_failed',
                'Workspace adapter must be one of: polyscope, opencode.',
                ['field' => 'adapter', 'adapter' => $this->stringOption('adapter')],
            );
        }

        $lookup = $this->stringOption('lookup') ?? 'workspace';

        if (! in_array($lookup, ['workspace', 'config'], true)) {
            return $this->renderFailure(
                'validation_failed',
                'Workspace adapter lookup must be one of: workspace, config.',
                ['field' => 'lookup', 'lookup' => $lookup],
            );
        }

        if ($lookup === 'config') {
            return $this->lookupConfig($adapter);
        }

        return $this->lookupWorkspace($adapter);
    }

    /**
     * @param  'polyscope'|'opencode'  $adapter
     */
    private function lookupWorkspace(string $adapter): int
    {
        $appPath = $this->requiredOption('app-path');

        if ($appPath === null) {
            return $this->missingRequiredOption('app-path');
        }

        $workspacePath = $this->requiredOption('workspace-path');

        if ($workspacePath === null) {
            return $this->missingRequiredOption('workspace-path');
        }

        $database = $this->openAdapterDatabase($adapter);

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            $payload = match ($adapter) {
                self::AdapterPolyscope => $this->lookupPolyscopeWorkspace($database, $appPath, $workspacePath),
                self::AdapterOpenCode => $this->lookupOpenCodeWorkspace($database, $appPath, $workspacePath),
            };
        } catch (PDOException) {
            return $this->renderFailure(
                'adapter_database_query_failed',
                'Workspace adapter database could not be queried.',
                ['adapter' => $adapter],
            );
        }

        return $this->emitInternalSuccess($payload);
    }

    /**
     * @param  'polyscope'|'opencode'  $adapter
     */
    private function lookupConfig(string $adapter): int
    {
        if ($adapter !== self::AdapterPolyscope) {
            return $this->renderFailure(
                'validation_failed',
                'Only the polyscope adapter supports config lookup.',
                ['field' => 'adapter', 'adapter' => $adapter, 'lookup' => 'config'],
            );
        }

        $appPath = $this->requiredOption('app-path');

        if ($appPath === null) {
            return $this->missingRequiredOption('app-path');
        }

        $settings = $this->readPolyscopeSettings();

        if (! is_array($settings)) {
            return $settings;
        }

        $database = $this->openAdapterDatabase($adapter);

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            $repositoryId = $this->lookupPolyscopeRepositoryId($database, $appPath);
        } catch (PDOException) {
            return $this->renderFailure(
                'adapter_database_query_failed',
                'Workspace adapter database could not be queried.',
                ['adapter' => $adapter],
            );
        }

        // Only non-secret config metadata. Raw Polyscope auth token (or any equivalent)
        // must never be emitted through the internal result boundary.
        return $this->emitInternalSuccess([
            'server_id' => $this->stringValue($settings['serverId'] ?? null),
            'repository_id' => $repositoryId,
            'base_url' => $this->stringValue($settings['backendUrl'] ?? null)
                ?? $this->stringValue($settings['backend'] ?? null),
        ]);
    }

    /**
     * @return array{match: false}|array{match: true, workspace_name: string, path: string, adapter_workspace_id: string}
     */
    private function lookupPolyscopeWorkspace(PDO $database, string $appPath, string $workspacePath): array
    {
        try {
            $statement = $this->query($database, <<<'SQL'
                select worktrees.id, worktrees.branch, worktrees.path, repositories.path as repository_path
                from worktrees
                left join repositories on repositories.id = worktrees.repo_id
                SQL);
        } catch (PDOException) {
            $statement = $this->query($database, 'select id, branch, path, null as repository_path from workspaces');
        }

        while (($row = $statement->fetch()) !== false) {
            $rowWorkspacePath = $this->stringValue($row['path'] ?? null);
            $rowRepositoryPath = $this->stringValue($row['repository_path'] ?? null);

            if ($rowWorkspacePath === null || $rowRepositoryPath === null) {
                continue;
            }

            if ($this->normalizePath($rowWorkspacePath) !== $this->normalizePath($workspacePath)) {
                continue;
            }

            if ($this->normalizePath($rowRepositoryPath) !== $this->normalizePath($appPath)) {
                continue;
            }

            return [
                'match' => true,
                'workspace_name' => $this->requiredRowString($row, 'branch'),
                'path' => $this->normalizePath($rowWorkspacePath),
                'adapter_workspace_id' => $this->requiredRowString($row, 'id'),
            ];
        }

        return ['match' => false];
    }

    /**
     * @return array{match: false}|array{match: true, workspace_name: string, path: string, adapter_workspace_id: string}
     */
    private function lookupOpenCodeWorkspace(PDO $database, string $appPath, string $workspacePath): array
    {
        $statement = $this->query($database, <<<'SQL'
            select workspace.id, workspace.name, workspace.branch, workspace.directory, project.worktree
            from workspace
            left join project on project.id = workspace.project_id
            SQL);

        while (($row = $statement->fetch()) !== false) {
            $directory = $this->stringValue($row['directory'] ?? null);
            $projectPath = $this->stringValue($row['worktree'] ?? null);

            if ($directory === null || $projectPath === null) {
                continue;
            }

            if ($this->normalizePath($directory) !== $this->normalizePath($workspacePath)) {
                continue;
            }

            if ($this->normalizePath($projectPath) !== $this->normalizePath($appPath)) {
                continue;
            }

            return [
                'match' => true,
                'workspace_name' => $this->stringValue($row['branch'] ?? null)
                    ?? $this->requiredRowString($row, 'name'),
                'path' => $this->normalizePath($directory),
                'adapter_workspace_id' => $this->requiredRowString($row, 'id'),
            ];
        }

        return ['match' => false];
    }

    private function lookupPolyscopeRepositoryId(PDO $database, string $appPath): ?string
    {
        $statement = $this->query($database, 'select id, path from repositories');

        while (($row = $statement->fetch()) !== false) {
            $repositoryPath = $this->stringValue($row['path'] ?? null);

            if ($repositoryPath === null) {
                continue;
            }

            if ($this->normalizePath($repositoryPath) !== $this->normalizePath($appPath)) {
                continue;
            }

            return $this->stringValue($row['id'] ?? null);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|int
     */
    private function readPolyscopeSettings(): array|int
    {
        $path = $this->polyscopeSettingsPath();

        if ($path === null) {
            return $this->renderFailure(
                'home_directory_unavailable',
                'Home directory could not be resolved for workspace adapter lookup.',
                ['adapter' => 'polyscope'],
            );
        }

        if (! is_file($path)) {
            return $this->renderFailure(
                'adapter_settings_missing',
                'Polyscope settings file does not exist.',
                ['adapter' => 'polyscope'],
            );
        }

        if (! is_readable($path)) {
            return $this->renderFailure(
                'adapter_settings_unreadable',
                'Polyscope settings file is not readable.',
                ['adapter' => 'polyscope'],
            );
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            return $this->renderFailure(
                'adapter_settings_unreadable',
                'Polyscope settings file is not readable.',
                ['adapter' => 'polyscope'],
            );
        }

        try {
            $settings = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->renderFailure(
                'adapter_settings_invalid',
                'Polyscope settings file contains invalid JSON.',
                ['adapter' => 'polyscope'],
            );
        }

        if (! is_array($settings)) {
            return $this->renderFailure(
                'adapter_settings_invalid',
                'Polyscope settings file must contain a JSON object.',
                ['adapter' => 'polyscope'],
            );
        }

        return $settings;
    }

    /**
     * @param  'polyscope'|'opencode'  $adapter
     */
    private function openAdapterDatabase(string $adapter): PDO|int
    {
        $path = $this->databasePath($adapter);

        if ($path === null) {
            return $this->renderFailure(
                'home_directory_unavailable',
                'Home directory could not be resolved for workspace adapter lookup.',
                ['adapter' => $adapter],
            );
        }

        if (! is_file($path)) {
            return $this->renderFailure(
                'adapter_database_missing',
                'Workspace adapter database does not exist.',
                ['adapter' => $adapter],
            );
        }

        if (! is_readable($path)) {
            return $this->renderFailure(
                'adapter_database_unreadable',
                'Workspace adapter database is not readable.',
                ['adapter' => $adapter],
            );
        }

        try {
            return new PDO("sqlite:{$path}", null, null, $this->sqliteReadOnlyOptions());
        } catch (PDOException) {
            return $this->renderFailure(
                'adapter_database_unreadable',
                'Workspace adapter database could not be opened.',
                ['adapter' => $adapter],
            );
        }
    }

    private function query(PDO $database, string $sql): PDOStatement
    {
        $statement = $database->query($sql);

        if (! $statement instanceof PDOStatement) {
            throw new PDOException('Query failed.');
        }

        return $statement;
    }

    /**
     * @return array<int, mixed>
     */
    private function sqliteReadOnlyOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS') && defined('PDO::SQLITE_OPEN_READONLY')) {
            $options[(int) constant('PDO::SQLITE_ATTR_OPEN_FLAGS')] = (int) constant('PDO::SQLITE_OPEN_READONLY');
        }

        return $options;
    }

    /**
     * @param  'polyscope'|'opencode'  $adapter
     */
    private function databasePath(string $adapter): ?string
    {
        $override = match ($adapter) {
            self::AdapterPolyscope => $this->environmentPath('ORBIT_POLYSCOPE_DB_PATH'),
            self::AdapterOpenCode => $this->environmentPath('ORBIT_OPENCODE_DB_PATH'),
        };

        if ($override !== null) {
            return $override;
        }

        $home = $this->homeDirectory();

        if ($home === null) {
            return null;
        }

        return match ($adapter) {
            self::AdapterPolyscope => "{$home}/.polyscope/polyscope.db",
            self::AdapterOpenCode => "{$home}/.local/share/opencode/opencode.db",
        };
    }

    private function polyscopeSettingsPath(): ?string
    {
        $override = $this->environmentPath('ORBIT_POLYSCOPE_SETTINGS_PATH');

        if ($override !== null) {
            return $override;
        }

        $home = $this->homeDirectory();

        return $home === null ? null : "{$home}/.polyscope/settings.json";
    }

    private function homeDirectory(): ?string
    {
        $home = $this->environmentPath('HOME');

        if ($home !== null) {
            return rtrim($home, '/');
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $entry = posix_getpwuid(posix_getuid());

            if (is_array($entry)) {
                return $this->stringValue($entry['dir']);
            }
        }

        return null;
    }

    private function environmentPath(string $key): ?string
    {
        $value = getenv($key);

        return $this->stringValue($value);
    }

    private function stringOption(string $name): ?string
    {
        return $this->stringValue($this->option($name));
    }

    /**
     * @return 'polyscope'|'opencode'|null
     */
    private function adapterOption(): ?string
    {
        $adapter = $this->stringOption('adapter');

        if ($adapter === self::AdapterPolyscope || $adapter === self::AdapterOpenCode) {
            return $adapter;
        }

        return null;
    }

    private function requiredOption(string $name): ?string
    {
        return $this->stringOption($name);
    }

    private function missingRequiredOption(string $field): int
    {
        return $this->renderFailure(
            'validation_failed',
            "The --{$field} option is required.",
            ['field' => $field, 'reason' => 'missing_required_input'],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requiredRowString(array $row, string $key): string
    {
        return $this->stringValue($row[$key] ?? null) ?? '';
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizePath(string $path): string
    {
        return rtrim($path, '/');
    }
}
