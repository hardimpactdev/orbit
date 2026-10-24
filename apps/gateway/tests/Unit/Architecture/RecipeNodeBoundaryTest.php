<?php

declare(strict_types=1);

use App\Actions\Fleet\ConvergeNodeRecipeAction;
use App\Actions\Fleet\MigrateDockerAction;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\Nodes\NativeNodeConverger;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmRuntime;

arch('Recipe, Docker migration and Pi runtime use the typed agent boundary')
    ->expect([ConvergeNodeRecipeAction::class, MigrateDockerAction::class, NodeRecipeProgram::class, TaskVmRuntime::class])
    ->not->toUse([NodeShell::class, SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);

arch('Shared PHP pools use the typed agent boundary')
    ->expect(RemoteAppDevPhpFpmManager::class)
    ->not->toUse([NodeShell::class, SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class, DevelopmentSshExecutor::class]);

arch('Node admission uses the typed agent boundary')
    ->expect(NativeNodeConverger::class)
    ->not->toUse([NodeShell::class, SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
