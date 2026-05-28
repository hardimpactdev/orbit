<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use PDO;
use PDOException;
use PDOStatement;

final class WorkspaceAdapterUpdateCommand extends InternalExecutorCommand
{
    private const string AdapterPolyscope = 'polyscope';

    private const string UpdateWorkspaceBranch = 'workspace-branch';

    private const int MaxWorkspaceIdLength = 255;

    private const int MaxBranchLength = 255;

    #[\Override]
    protected $signature = 'internal:workspace-adapter:update
        {--adapter=}
        {--update=}
        {--workspace-id=}
        {--branch=}
        {--operation-token=}
        {--json}';

    #[\Override]
    protected $description = 'Update agent IDE workspace adapter state';

    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        if (! $this->verifyOperationToken('internal:workspace-adapter:update')) {
            return self::FAILURE;
        }

        $adapter = $this->stringValue($this->option('adapter'));

        if ($adapter !== self::AdapterPolyscope) {
            return $this->renderFailure(
                'validation_failed',
                'Workspace adapter update supports only the polyscope adapter.',
                ['field' => 'adapter', 'adapter' => $adapter],
            );
        }

        $update = $this->stringValue($this->option('update'));

        if ($update !== self::UpdateWorkspaceBranch) {
            return $this->renderFailure(
                'validation_failed',
                'Workspace adapter update must be workspace-branch.',
                ['field' => 'update', 'update' => $update],
            );
        }

        $workspaceId = $this->workspaceIdValue($this->option('workspace-id'));

        if ($workspaceId === null) {
            return $this->invalidOption('workspace-id');
        }

        $branch = $this->branchValue($this->option('branch'));

        if ($branch === null) {
            return $this->invalidOption('branch');
        }

        return $this->updateWorkspaceBranch($workspaceId, $branch);
    }

    private function updateWorkspaceBranch(string $workspaceId, string $branch): int
    {
        $database = $this->openWritablePolyscopeDatabase();

        if (! $database instanceof PDO) {
            return $database;
        }

        try {
            $statement = $this->prepare($database, <<<'SQL'
                update worktrees
                set branch = :branch,
                    branch_renamed = 1
                where id = :workspace_id
                SQL);
            $statement->bindValue(':branch', $branch, PDO::PARAM_STR);
            $statement->bindValue(':workspace_id', $workspaceId, PDO::PARAM_STR);
            $statement->execute();
        } catch (PDOException) {
            return $this->renderFailure(
                'update_failed',
                'Polyscope database update failed.',
                ['adapter' => self::AdapterPolyscope, 'update' => self::UpdateWorkspaceBranch],
            );
        }

        if ($statement->rowCount() !== 1) {
            return $this->renderFailure(
                'workspace_not_found',
                'Polyscope workspace was not found.',
                ['adapter' => self::AdapterPolyscope, 'workspace_id' => $workspaceId],
            );
        }

        return $this->emitInternalSuccess([
            'adapter' => self::AdapterPolyscope,
            'update' => self::UpdateWorkspaceBranch,
            'workspace_id' => $workspaceId,
            'branch' => $branch,
            'updated' => true,
        ]);
    }

    private function openWritablePolyscopeDatabase(): PDO|int
    {
        $path = $this->databasePath();

        if ($path === null) {
            return $this->renderFailure(
                'home_directory_unavailable',
                'Home directory could not be resolved for workspace adapter update.',
                ['adapter' => self::AdapterPolyscope],
            );
        }

        if (! is_file($path)) {
            return $this->renderFailure(
                'database_missing',
                'Polyscope database does not exist.',
                ['adapter' => self::AdapterPolyscope],
            );
        }

        if (! is_writable($path)) {
            return $this->renderFailure(
                'database_unwritable',
                'Polyscope database is not writable.',
                ['adapter' => self::AdapterPolyscope],
            );
        }

        try {
            return new PDO("sqlite:{$path}", null, null, $this->sqliteOptions());
        } catch (PDOException) {
            return $this->renderFailure(
                'database_unwritable',
                'Polyscope database could not be opened for writing.',
                ['adapter' => self::AdapterPolyscope],
            );
        }
    }

    private function prepare(PDO $database, string $sql): PDOStatement
    {
        $statement = $database->prepare($sql);

        if (! $statement instanceof PDOStatement) {
            throw new PDOException('Statement could not be prepared.');
        }

        return $statement;
    }

    /**
     * @return array<int, mixed>
     */
    private function sqliteOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
    }

    private function databasePath(): ?string
    {
        $override = $this->environmentPath('ORBIT_POLYSCOPE_DB_PATH');

        if ($override !== null) {
            return $override;
        }

        $home = $this->homeDirectory();

        return $home === null ? null : "{$home}/.polyscope/polyscope.db";
    }

    private function invalidOption(string $field): int
    {
        return $this->renderFailure(
            'validation_failed',
            "The --{$field} option is invalid.",
            ['field' => $field],
        );
    }

    private function workspaceIdValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > self::MaxWorkspaceIdLength) {
            return null;
        }

        return $value;
    }

    private function branchValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            return null;
        }

        if (trim($value) === '' || strlen($value) > self::MaxBranchLength) {
            return null;
        }

        return $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (str_contains($value, "\0")) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function environmentPath(string $key): ?string
    {
        $value = getenv($key);

        return $this->stringValue($value);
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
}
