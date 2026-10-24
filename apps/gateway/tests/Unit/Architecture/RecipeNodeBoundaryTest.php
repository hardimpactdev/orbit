<?php

declare(strict_types=1);

use App\Actions\Fleet\ConvergeNodeRecipeAction;
use App\Actions\Fleet\MigrateDockerAction;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmRuntime;

arch('Recipe, Docker migration and Pi runtime use the typed agent boundary')
    ->expect([ConvergeNodeRecipeAction::class, MigrateDockerAction::class, NodeRecipeProgram::class, TaskVmRuntime::class])
    ->not->toUse([NodeShell::class, SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
