<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;

final class AppDevFakeSshExecutor implements SshExecutor
{
    /** What the PHP build installer prints when a test queues an empty success for it. */
    public const string InstallerApplied = '{"outcome":"applied","data":{"changed":[],"purged":[],"restarted":[],"deferred":[],"stale_masters":[]}}'."\n";

    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results = [],
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;
        $result = array_shift($this->results) ?? new CommandResult(0, '', '', 1, false);

        // A queued empty success stands for a successful run of the PHP build installer (ADR 0216).
        if (($command->arguments[4] ?? null) === 'php.install-build' && $result->exitCode === 0 && $result->stdout === '') {
            return new CommandResult(0, self::InstallerApplied, $result->stderr, $result->durationMs, false);
        }

        return $result;
    }
}
