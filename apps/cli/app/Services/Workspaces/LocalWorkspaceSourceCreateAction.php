<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use Symfony\Component\Process\Process;

final readonly class LocalWorkspaceSourceCreateAction
{
    private const int MaxRefLength = 255;

    /**
     * @return array<string, mixed>
     */
    public function create(mixed $appPath, mixed $workspace, mixed $base): array
    {
        $appPath = $this->absolutePath($appPath, 'app-path');
        $workspace = $this->ref($workspace, 'workspace', allowSlash: false);
        $base = $this->ref($base, 'base', allowSlash: true);
        $relativePath = ".worktrees/{$workspace}";
        $workspacePath = "{$appPath}/{$relativePath}";

        if (file_exists($workspacePath)) {
            throw new LocalWorkspaceSourceCreateFailure(
                errorCode: 'workspace_path_exists',
                message: 'Workspace path already exists.',
                meta: ['path' => $workspacePath],
            );
        }

        $commands = [
            $this->mustRun(['mkdir', '-p', "{$appPath}/.worktrees"]),
            $this->mustRun(['git', '-C', $appPath, 'worktree', 'add', $relativePath, '-b', $workspace, $base]),
        ];

        return [
            'app_path' => $appPath,
            'workspace' => $workspace,
            'base' => $base,
            'path' => $workspacePath,
            'commands' => $commands,
        ];
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>, exit_code: int|null}
     */
    private function mustRun(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $error = $error !== '' ? $error : trim($process->getOutput());
            $error = $error !== '' ? $error : 'workspace source creation command failed';

            throw new LocalWorkspaceSourceCreateFailure(
                errorCode: 'workspace_source_create_failed',
                message: $error,
                meta: [
                    'command' => $command[0],
                    'exit_code' => $process->getExitCode(),
                ],
            );
        }

        return [
            'command' => $command,
            'exit_code' => $process->getExitCode(),
        ];
    }

    private function absolutePath(mixed $value, string $field): string
    {
        if (is_string($value) && $value !== '' && str_starts_with($value, '/') && ! str_contains($value, "\0")) {
            return rtrim(string: $value, characters: '/');
        }

        throw new LocalWorkspaceSourceCreateFailure(
            errorCode: 'validation_failed',
            message: "The {$field} value is invalid.",
            meta: ['field' => $field],
        );
    }

    private function ref(mixed $value, string $field, bool $allowSlash): string
    {
        if (! is_string($value)) {
            throw $this->invalidRef($field);
        }

        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw $this->invalidRef($field);
        }

        if (trim($value) === '' || strlen($value) > self::MaxRefLength || ! $allowSlash && str_contains($value, '/')) {
            throw $this->invalidRef($field);
        }

        return $value;
    }

    private function invalidRef(string $field): LocalWorkspaceSourceCreateFailure
    {
        return new LocalWorkspaceSourceCreateFailure(
            errorCode: 'validation_failed',
            message: "The {$field} value is invalid.",
            meta: ['field' => $field],
        );
    }
}
