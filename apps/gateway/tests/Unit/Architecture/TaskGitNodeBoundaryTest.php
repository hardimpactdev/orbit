<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AgentCommands\AgentInvocation;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmGit;

arch('task VM Git leaves Node transport and pin handling to the agent runner')
    ->expect(TaskVmGit::class)
    ->not->toUse([SshExecutor::class, RemoteProgramRunner::class, AgentTransport::class, AgentInvocation::class]);
