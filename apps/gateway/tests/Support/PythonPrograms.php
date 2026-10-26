<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Runs the excluded Tasks programs in isolated processes, with no Python test host. */
final readonly class PythonPrograms
{
    public function __construct(private float $timeoutSeconds = 60) {}

    /** @param array<string, string|false> $environment */
    public function run(string $bootstrap, string $name, string $input, array $environment = []): CommandResult
    {
        return $this->execute(['python3', '-I', '-c', $bootstrap, $name], $input, $environment, $this->timeoutSeconds);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    public function runCommand(string $source, array $arguments, string $input = '', array $environment = [], ?float $timeoutSeconds = null): CommandResult
    {
        return $this->execute(['python3', '-I', '-c', $source, ...$arguments], $input, $environment, $timeoutSeconds ?? $this->timeoutSeconds);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    private function execute(array $arguments, string $input, array $environment, float $timeoutSeconds): CommandResult
    {
        $environment = array_replace(getenv(), $environment);
        $environment['PATH'] = NoSudo::path((string) ($environment['PATH'] ?? ''));
        $process = new Process(['setsid', '--', ...$arguments], getcwd(), $environment, $input, $timeoutSeconds);
        $started = hrtime(true);
        $timedOut = false;
        $process->start();
        $pid = $process->getPid();
        try {
            $process->wait();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        } finally {
            // Reap descendants too, including a child that outlives the Python leader.
            if (is_int($pid)) {
                @posix_kill(-$pid, SIGKILL);
            }
            if ($process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
        }

        return new CommandResult(
            $timedOut ? 124 : ($process->getExitCode() ?? 1),
            $process->getOutput(),
            $process->getErrorOutput().($timedOut ? "Python test program timed out.\n" : ''),
            (int) ((hrtime(true) - $started) / 1_000_000),
            false,
        );
    }
}
