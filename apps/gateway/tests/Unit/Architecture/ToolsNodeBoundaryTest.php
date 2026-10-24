<?php

declare(strict_types=1);

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\VpInventoryInspector;
use App\Infrastructure\Tools\VpToolManager;

arch('Project environment preparation sends typed agent input')
    ->expect([ComposerToolManager::class, AptToolManager::class, PrepareVpEnvironmentAction::class, VpToolManager::class, VpInventoryInspector::class])
    ->not->toUse([
        RemoteProgramRunner::class,
        RemoteProgramCatalog::class,
        SshExecutor::class,
        RemoteToolCommandRunner::class,
        ProcessRunner::class,
    ]);
