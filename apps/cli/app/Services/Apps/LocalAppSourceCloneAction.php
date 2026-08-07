<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Symfony\Component\Process\Process;

final readonly class LocalAppSourceCloneAction
{
    public function __construct(
        private LocalGitRepositoryReference $repositories = new LocalGitRepositoryReference,
    ) {}

    /**
     * @return array{command: list<string>, exit_code: int|null, reused: bool}
     */
    public function clone(string $repository, string $path): array
    {
        $reused = $this->reuse($repository, $path);

        if ($reused !== null) {
            return $reused;
        }

        $githubSlug = $this->repositories->githubSlug($repository);
        $command = $githubSlug !== null
            ? ['gh', 'repo', 'clone', $githubSlug, $path]
            : ['git', 'clone', $repository, $path];

        return [
            ...$this->mustRun($command),
            'reused' => false,
        ];
    }

    /**
     * @return array{command: list<string>, exit_code: int, reused: true}|null
     */
    public function reuse(string $repository, string $path): ?array
    {
        if (file_exists($path) && ! is_dir($path)) {
            throw new LocalAppSourceCreateFailure(
                errorCode: 'app_source_create_failed',
                message: "App path {$path} already exists and is not a directory.",
                meta: ['path' => $path],
            );
        }

        if (! is_dir($path) || $this->directoryIsEmpty($path)) {
            return null;
        }

        if (! is_dir("{$path}/.git")) {
            throw new LocalAppSourceCreateFailure(
                errorCode: 'app_source_create_failed',
                message: "App path {$path} already exists and is not a git checkout.",
                meta: ['path' => $path],
            );
        }

        $origin = $this->gitOrigin($path);

        if ($this->repositories->matchesOrigin($repository, $origin)) {
            return [
                'command' => ['git', '-C', $path, 'remote', 'get-url', 'origin'],
                'exit_code' => 0,
                'reused' => true,
            ];
        }

        throw new LocalAppSourceCreateFailure(
            errorCode: 'app_source_create_failed',
            message: "App path {$path} already exists with origin '{$origin}'.",
            meta: ['path' => $path, 'origin' => $origin],
        );
    }

    private function directoryIsEmpty(string $path): bool
    {
        $entries = scandir($path);

        if ($entries === false) {
            return false;
        }

        return array_values(array_diff($entries, ['.', '..'])) === [];
    }

    private function gitOrigin(string $path): string
    {
        $process = $this->run(['git', '-C', $path, 'remote', 'get-url', 'origin']);

        if (! $process->isSuccessful()) {
            return '';
        }

        return trim($process->getOutput());
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): Process
    {
        $environment = ['GIT_TERMINAL_PROMPT' => '0'];

        if ($command[0] === 'gh') {
            $environment['GH_HOST'] = 'github.com';
            $environment['GH_PROMPT_DISABLED'] = '1';
        }

        $process = new Process($command, env: $environment);
        $process->setTimeout(300);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>, exit_code: int|null}
     */
    private function mustRun(array $command): array
    {
        $process = $this->run($command);

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $error = $error !== '' ? $error : trim($process->getOutput());
            $error = $error !== '' ? $error : 'app source creation command failed';

            throw new LocalAppSourceCreateFailure(
                errorCode: 'app_source_create_failed',
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
}
