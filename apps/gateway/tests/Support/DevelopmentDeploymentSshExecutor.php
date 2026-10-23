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
 * Runs each agent command that the runner sends with the agent binary built from this checkout, locally. Git reads
 * the fixture's global configuration, which points the Project's remote URL at the local origin.
 */
final class DevelopmentDeploymentSshExecutor implements SshExecutor
{
    public function __construct(private readonly string $global, private readonly string $sandbox) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $arguments = LocalAgentBinary::localArguments($command->arguments)
            ?? throw new RuntimeException('Only agent commands reach a development Node in this fixture.');

        return new NativeProcessRunner()->run(new ProcessInvocation(
            $arguments,
            timeout: $command->timeout ?? 30,
            protectedInput: $command->protectedInput ?? ProtectedInput::fromString(''),
            maxOutputBytes: $command->maxOutputBytes,
            environment: LocalAgentBinary::environment($this->sandbox, ['GIT_CONFIG_GLOBAL' => $this->global, 'GIT_CONFIG_NOSYSTEM' => '1']),
        ));
    }
}
