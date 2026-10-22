<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs finite remote programs locally. SSH does not forward local Composer credentials.
 * bash -seu programs, optionally with -- and arguments, are complete source; other command inputs remain data.
 * Protected inputs remain owned by the caller.
 */
final class LocalShellSshExecutor implements SshExecutor
{
    /** @var array<string, string> */
    public array $environment = [];

    /** @var (Closure(string): string)|null */
    public ?Closure $transformInput = null;

    public function __construct(private readonly ?string $workingDirectory = null, private readonly ?string $programDirectory = null) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $input = $command->protectedInput instanceof ProtectedInput ? stream_get_contents($command->protectedInput->stream()) : $command->input;
        HostPathGuardedProcessRunner::assertSafe($command->arguments, is_string($input) ? $input : null);
        if ($this->transformInput !== null) {
            $input = ($this->transformInput)($input ?? '');
        }
        $program = null;
        $arguments = $command->arguments;
        try {
            if ($arguments === ['bash', '-seu']
                || array_slice($arguments, 0, 3) === ['bash', '-seu', '--']) {
                $directory = $this->programDirectory ?? sys_get_temp_dir();
                if (! is_dir($directory)) {
                    throw new RuntimeException('The finite Bash program directory is missing.');
                }
                $program = tempnam($directory, '.orbit-program.');
                if ($program === false || file_put_contents($program, $input ?? '') !== strlen($input ?? '')) {
                    throw new RuntimeException('Could not stage the finite Bash program.');
                }
                $arguments = ['bash', '-ceu', 'fixture_program=$1; shift; source "$fixture_program" "$@"', 'bash', $program, ...array_slice($arguments, 3)];
                $input = null;
            }
            $process = new Process($arguments, $this->workingDirectory, [...$this->environment, 'COMPOSER_AUTH' => false], $input);
            $process->run();

            return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
        } finally {
            if (is_string($program)) {
                unlink($program);
            }
        }
    }
}
