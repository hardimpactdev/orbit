<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use LogicException;

/**
 * Refuses a privileged local command that names a real host path Orbit manages. A command is privileged when
 * the suite runs as root, or when `sudo` is an argument or a command word in a script it runs, on stdin too.
 * `LocalShellSshExecutor` checks its commands the same way.
 *
 * Production code reaches the host through the container's ProcessRunner. A test that leaves it real and keeps
 * the default `/etc/systemd`, `/etc/dnsmasq.d`, or `/var/lib/orbit` paths would change the machine that runs the
 * suite. A temporary root such as `/tmp/orbit-x/etc/systemd` does not match.
 */
final readonly class HostPathGuardedProcessRunner implements ProcessRunner
{
    private const string HostPath = '#(?<![\w.~/-])/(?:etc/systemd|etc/dnsmasq(?:\.d|\.conf)?|var/lib/orbit)(?![\w.-])#';

    public function __construct(private ProcessRunner $runner) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        self::assertSafe($invocation->arguments, $invocation->input);

        return $this->runner->run($invocation);
    }

    /** A `sudo` command word, as an argument or inside a script in an argument or on stdin. */
    private const string Sudo = '#(?<![\w.~/-])sudo\s#';

    /** @param list<string> $arguments */
    public static function assertSafe(array $arguments, ?string $input): void
    {
        $text = implode("\n", $arguments)."\n".($input ?? '');
        if (posix_geteuid() !== 0 && preg_match(self::Sudo, $text) !== 1) {
            return;
        }

        if (preg_match(self::HostPath, $text, $match) === 1) {
            throw new LogicException(
                "A test ran a privileged command against the host path {$match[0]}. "
                .'Give the code under test a temporary root or a fake ProcessRunner.',
            );
        }
    }
}
