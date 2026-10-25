<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use LogicException;
use Symfony\Component\Process\Process;

/**
 * Runs finite fixture commands with Symfony's pipe-driven wait, not the production runner's
 * cancellation polling. Lifecycle, cancellation and output-stream proofs use NativeProcessRunner.
 */
final readonly class FixtureProcessRunner implements ProcessRunner
{
    public function run(ProcessInvocation $invocation): CommandResult
    {
        if ($invocation->cancelled !== null || $invocation->output !== null) {
            throw new LogicException('Streaming and cancellation proofs require NativeProcessRunner.');
        }

        $started = hrtime(true);
        $process = new Process($invocation->arguments, env: $invocation->environment, timeout: $invocation->timeout);
        $protectedInput = $invocation->protectedInput;

        try {
            $process->setInput($protectedInput?->chunks() ?? $invocation->input);
            $process->run();
            $limit = $invocation->maxOutputBytes ?? 1_048_576;
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            return new CommandResult(
                exitCode: $process->getExitCode() ?? 1,
                stdout: substr($stdout, -$limit),
                stderr: substr($stderr, -$limit),
                durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
                truncated: strlen($stdout) > $limit || strlen($stderr) > $limit,
            );
        } finally {
            $protectedInput?->close();
        }
    }
}
