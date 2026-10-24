<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\FinalizeGatewayReleaseAction;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AgentView\NativeAgentViewConverger;
use App\Infrastructure\Fleet\NativeFleetConvergeUnits;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Gateway\GatewayServiceCommands;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseProcessDeclaration;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecovery;
use App\Infrastructure\GatewayReleases\GatewayReleaseRestore;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseExecutionOwner;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseUnitConverger;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseDrain;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseUnitStarter;
use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshExecutor;

arch('Release preparation, database steps and units use the typed agent boundary')
    ->expect([
        ArtisanGatewayReleaseRuntime::class,
        GatewayServiceCommands::class,
        NativeFleetConvergeUnits::class,
        NativeAgentViewConverger::class,
        NativeRuntimeHibernatorConverger::class,
        GatewayReleaseBuilder::class,
        GitHubArtifactWebBuild::class,
        GatewayReleaseProcessDeclaration::class,
        GatewayReleaseRecovery::class,
        GatewayReleaseRestore::class,
        SystemdGatewayReleaseDrain::class,
        GatewaySchedulerHandoff::class,
        SqliteGatewayReleaseDatabase::class,
        FinalizeGatewayReleaseAction::class,
        NativeGatewayReleaseUnitConverger::class,
        NativeGatewayReleaseExecutionOwner::class,
        SystemdGatewayReleaseUnitStarter::class,
    ])
    ->not->toUse([NodeShell::class, SshExecutor::class, ProcessRunner::class, RemoteProgramRunner::class]);
