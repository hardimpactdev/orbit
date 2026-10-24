<?php

declare(strict_types=1);

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Infrastructure\Tools\HomebrewCommand;
use App\Infrastructure\Tools\HomebrewInventoryInspector;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\VpInventoryInspector;
use App\Infrastructure\Tools\VpToolManager;

arch('Project environment preparation sends typed agent input')
    ->expect([HomebrewCaskToolManager::class, HomebrewInventoryInspector::class, HomebrewToolManager::class, ComposerToolManager::class, AptToolManager::class, HomebrewCommand::class, PrepareVpEnvironmentAction::class, VpToolManager::class, VpInventoryInspector::class])
    ->not->toUse([
        RemoteProgramRunner::class,
        RemoteProgramCatalog::class,
        SshExecutor::class,
        ProcessRunner::class,
    ]);
