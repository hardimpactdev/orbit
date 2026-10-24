<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Nodes\Roles\DevelopmentRoleBaseline;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Ssh\SshExecutor;

arch('Hibernation and its role setup use the agent boundary')
    ->expect([
        'App\\Infrastructure\\Hibernation',
        DevelopmentRoleBaseline::class,
    ])
    ->not->toUse([
        RemoteProgramRunner::class,
        RemoteProgramCatalog::class,
        SshExecutor::class,
        ProcessRunner::class,
        DevelopmentSshExecutor::class,
    ]);
