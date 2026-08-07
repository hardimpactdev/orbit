<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Symfony\Component\Process\Process;

final readonly class LocalAppSourceCreateAction
{
    public function __construct(
        private LocalAppSourceCloneAction $cloner = new LocalAppSourceCloneAction,
        private LocalAppSourceTemplateAction $templateCreator = new LocalAppSourceTemplateAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(
        mixed $user,
        mixed $path,
        mixed $repository,
        mixed $templateRepository,
        mixed $newRepository,
    ): array {
        $user = $this->user($user);
        $path = $this->absolutePath($path, 'path');
        $source = LocalAppSourcePlan::fromInput($repository, $templateRepository, $newRepository);
        $commands = [];

        $commands[] = $this->mustRun([
            'sudo',
            'install',
            '-d',
            '-m',
            '755',
            '-o',
            $user,
            '-g',
            $user,
            dirname($path),
        ]);

        if ($source->templateRepository === null) {
            $commands[] = $this->cloner->clone($source->repository, $path);

            return [
                'user' => $user,
                'path' => $path,
                'repository' => $source->repository,
                'commands' => $commands,
            ];
        }

        array_push(
            $commands,
            ...$this->templateCreator->create($source->templateRepository, $source->repository, $path),
        );

        return [
            'user' => $user,
            'path' => $path,
            'repository' => $source->repository,
            'template_repository' => $source->templateRepository,
            'commands' => $commands,
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): Process
    {
        $process = new Process($command);
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

    private function user(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[a-z_][a-z0-9_-]*[$]?\z/', $value) === 1) {
            return $value;
        }

        throw new LocalAppSourceCreateFailure(
            errorCode: 'validation_failed',
            message: 'App source owner is invalid.',
            meta: ['field' => 'user'],
        );
    }

    private function absolutePath(mixed $value, string $field): string
    {
        if (is_string($value) && $value !== '' && str_starts_with($value, '/') && ! str_contains($value, "\0")) {
            return rtrim(string: $value, characters: '/');
        }

        throw new LocalAppSourceCreateFailure(
            errorCode: 'validation_failed',
            message: "{$field} must be an absolute path.",
            meta: ['field' => $field],
        );
    }
}
