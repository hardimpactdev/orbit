<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use LogicException;
use PHPUnit\Framework\Assert;

/** Guards the container's native runner, including a directly resolved NativeSshExecutor. */
final class SshGuardedProcessRunner implements ProcessRunner
{
    /** @var list<string> */
    private array $blocked = [];

    public function __construct(private readonly ProcessRunner $runner) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $program = basename($invocation->arguments[0]);

        if (in_array($program, ['ssh', 'ssh-keyscan'], true)) {
            $this->blocked[] = $program;
            $invocation->protectedInput?->close();

            throw new LogicException(self::Message);
        }

        return $this->runner->run($invocation);
    }

    /** Fails even when the caller swallowed the guard's exception. */
    public function assertNoStrayProcesses(): void
    {
        Assert::assertSame([], $this->blocked, self::Message);
    }

    private const string Message = 'Feature tests cannot start real ssh or ssh-keyscan processes. '
        .'Bind a fake SshExecutor or HostKeyScanner. To opt into a real transport test, explicitly rebind NativeProcessRunner.';
}
