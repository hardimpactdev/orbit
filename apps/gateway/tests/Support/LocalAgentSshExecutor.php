<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use RuntimeException;

/**
 * Runs each agent command that the runner sends with the agent binary built from this checkout, locally, as the
 * current user. Only account commands run so: an owner or root command needs root.
 */
final class LocalAgentSshExecutor implements SshExecutor
{
    /** @param  array<string, string>  $environment  Added to each command's environment, such as Git configuration. */
    public function __construct(private readonly string $sandbox, private readonly array $environment = []) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $arguments = LocalAgentBinary::localArguments($command->arguments)
            ?? throw new RuntimeException('Only agent commands reach a development Node in this fixture.');

        return new NativeProcessRunner()->run(new ProcessInvocation(
            $arguments,
            timeout: $command->timeout ?? 30,
            protectedInput: $command->protectedInput ?? ProtectedInput::fromString(''),
            maxOutputBytes: $command->maxOutputBytes,
            environment: LocalAgentBinary::environment($this->sandbox, $this->environment),
        ));
    }
}
