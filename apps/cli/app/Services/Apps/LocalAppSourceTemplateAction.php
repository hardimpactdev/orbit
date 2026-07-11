<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Symfony\Component\Process\Process;

final readonly class LocalAppSourceTemplateAction
{
    public function __construct(
        private LocalAppSourceCloneAction $cloner = new LocalAppSourceCloneAction,
        private LocalAppSourceTemplateRepositoryVerifier $verifier = new LocalAppSourceTemplateRepositoryVerifier,
    ) {}

    /**
     * @return list<array{command: list<string>, exit_code: int|null, reused?: bool}>
     */
    public function create(string $templateRepository, string $newRepository, string $path): array
    {
        $reusedCheckout = $this->cloner->reuse($newRepository, $path);

        if ($reusedCheckout !== null) {
            return [
                $reusedCheckout,
                $this->verifyExistingRepository($templateRepository, $newRepository),
            ];
        }

        $createCommand = [
            'gh',
            'repo',
            'create',
            $newRepository,
            '--private',
            '--template',
            $templateRepository,
        ];
        $createProcess = $this->run($createCommand);
        $commands = [$this->commandResult($createCommand, $createProcess)];

        if (! $createProcess->isSuccessful()) {
            $commands[] = $this->verifyExistingRepository($templateRepository, $newRepository);
        }

        $commands[] = $this->cloner->clone($newRepository, $path);

        return $commands;
    }

    /**
     * @return array{command: list<string>, exit_code: int|null}
     */
    private function verifyExistingRepository(string $templateRepository, string $newRepository): array
    {
        $viewCommand = [
            'gh',
            'repo',
            'view',
            $newRepository,
            '--json',
            'templateRepository,visibility',
        ];
        $viewProcess = $this->run($viewCommand);

        if (! $viewProcess->isSuccessful()) {
            $this->throwFailure($viewCommand, $viewProcess);
        }

        $this->verifier->verify($templateRepository, $newRepository, $viewProcess->getOutput());

        return $this->commandResult($viewCommand, $viewProcess);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): Process
    {
        $process = new Process($command, env: [
            'GH_HOST' => 'github.com',
            'GH_PROMPT_DISABLED' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->setTimeout(300);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>, exit_code: int|null}
     */
    private function commandResult(array $command, Process $process): array
    {
        return [
            'command' => $command,
            'exit_code' => $process->getExitCode(),
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function throwFailure(array $command, Process $process): never
    {
        $error = trim($process->getErrorOutput());
        $error = $error !== '' ? $error : trim($process->getOutput());
        $error = $error !== '' ? $error : 'app source repository verification failed';

        throw new LocalAppSourceCreateFailure(
            errorCode: 'app_source_create_failed',
            message: $error,
            meta: [
                'command' => $command[0],
                'exit_code' => $process->getExitCode(),
            ],
        );
    }
}
