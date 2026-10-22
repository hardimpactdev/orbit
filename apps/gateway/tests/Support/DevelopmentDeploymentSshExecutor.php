<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;

/** Executes the real checkout programs locally, with case-local shell instrumentation. */
final class DevelopmentDeploymentSshExecutor implements SshExecutor
{
    /** @var array<string, string> */
    public array $environment = [];

    public function __construct(private readonly string $global) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        return new NativeProcessRunner()->run(new ProcessInvocation(
            $command->arguments,
            timeout: $command->timeout ?? 30,
            input: $command->input,
            protectedInput: $command->protectedInput,
            maxOutputBytes: $command->maxOutputBytes,
            output: $command->output,
            cancelled: $command->cancelled,
            terminateGraceSeconds: $command->terminateGraceSeconds,
            environment: ['GIT_CONFIG_GLOBAL' => $this->global, 'GIT_CONFIG_NOSYSTEM' => '1', ...$this->environment],
        ));
    }
}
