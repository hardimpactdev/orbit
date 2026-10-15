<?php

declare(strict_types=1);

/*
 * Files under app/ that still hold an inline script for a Node: a nowdoc or heredoc labelled BASH, PY, PYTHON, SH,
 * SHELL, or AWK. New Node code is a remote program under resources/remote (ADR 0211, docs/reference/remote-programs.md).
 * This list only shrinks: migrate a file and remove it here. Caddy, configuration, and text templates are not listed.
 */
const InlineRemoteScriptFiles = [
    'app/Infrastructure/Analytics/AnalyticsCertificatePublisher.php',
    'app/Infrastructure/Analytics/NativeAnalyticsPublicationManager.php',
    'app/Infrastructure/AppDev/DnsmasqPrivateDnsManager.php',
    'app/Infrastructure/AppDev/RemoteAppDevCertificateManager.php',
    'app/Infrastructure/AppDev/RemoteAppDevPhpFpmManager.php',
    'app/Infrastructure/AppDev/RemoteAppDevRouteFirewallManager.php',
    'app/Infrastructure/AppDev/RemoteAppDevTldRouteManager.php',
    'app/Infrastructure/AppDev/RemoteVitePortRuntime.php',
    'app/Infrastructure/Caddy/Build/NodeCaddyLiveReader.php',
    'app/Infrastructure/Caddy/Build/NodeCaddyPushScript.php',
    'app/Infrastructure/Caddy/CaddyPublicationLock.php',
    'app/Infrastructure/DatabaseServers/RemoteDatabaseServerAdmin.php',
    'app/Infrastructure/Doctor/NativeCustomProxyRouteInspector.php',
    'app/Infrastructure/Doctor/NativeGatewayVpnStateInspector.php',
    'app/Infrastructure/Doctor/NativeInstanceStateInspector.php',
    'app/Infrastructure/Doctor/NativePrivateRouteProjectionInspector.php',
    'app/Infrastructure/Doctor/NativePublicRouteEdgeInspector.php',
    'app/Infrastructure/Doctor/NativeRoleStateInspector.php',
    'app/Infrastructure/Doctor/NativeRouteApplicationUrlInspector.php',
    'app/Infrastructure/Doctor/NativeScheduleStateInspector.php',
    'app/Infrastructure/Doctor/SshNodeStateInspector.php',
    'app/Infrastructure/Firewall/UfwStoredRuleProbe.php',
    'app/Infrastructure/Fleet/Footprint/TmpfilesFootprintArtifact.php',
    'app/Infrastructure/Gateway/GatewayCheckoutAccessConverger.php',
    'app/Infrastructure/Gateway/GatewayCleanupStartupRenderer.php',
    'app/Infrastructure/Gateway/GatewayPrivateDnsResolver.php',
    'app/Infrastructure/Gateway/GatewayWebDirectoryConverger.php',
    'app/Infrastructure/Gateway/NativeGatewayCertificatePublisher.php',
    'app/Infrastructure/Gateway/NativeGatewayFpmConverger.php',
    'app/Infrastructure/GatewayReleases/GatewayNodeAgentUpdate.php',
    'app/Infrastructure/GatewayReleases/GatewayReleaseExchange.php',
    'app/Infrastructure/Hibernation/HibernationDirectoryEnsure.php',
    'app/Infrastructure/Hibernation/RemoteInstanceCheckoutInspector.php',
    'app/Infrastructure/Hibernation/RemoteInstanceRuntimeReadiness.php',
    'app/Infrastructure/Instances/BunDependencyUpdatePresenceProgram.php',
    'app/Infrastructure/Instances/BunDependencyUpdateProgram.php',
    'app/Infrastructure/Instances/ComposerDependencyUpdatePresenceProgram.php',
    'app/Infrastructure/Instances/ComposerDependencyUpdateProgram.php',
    'app/Infrastructure/Instances/DependencyFilesProgram.php',
    'app/Infrastructure/Instances/DependencyUpdateSupervisorHost.php',
    'app/Infrastructure/Instances/DevelopmentCaddyAccessCommand.php',
    'app/Infrastructure/Instances/DevelopmentCheckoutProgram.php',
    'app/Infrastructure/Instances/NpmDependencyUpdatePresenceProgram.php',
    'app/Infrastructure/Instances/NpmDependencyUpdateProgram.php',
    'app/Infrastructure/Instances/PnpmDependencyUpdatePresenceProgram.php',
    'app/Infrastructure/Instances/ProductionRuntimeGenerationProgram.php',
    'app/Infrastructure/Instances/ProductionWebRootProgram.php',
    'app/Infrastructure/Instances/RemoteDevelopmentInstanceConfigurator.php',
    'app/Infrastructure/Instances/RemoteDevelopmentInstanceSourceLifecycle.php',
    'app/Infrastructure/Instances/RemoteInstanceCloneCandidateInspector.php',
    'app/Infrastructure/Instances/RemoteInstanceDestinationGuard.php',
    'app/Infrastructure/Instances/RemoteInstanceEnvironmentAccess.php',
    'app/Infrastructure/Instances/RemoteInstanceLogReader.php',
    'app/Infrastructure/Instances/RemoteInstanceSqliteCloner.php',
    'app/Infrastructure/Instances/RemoteInstanceSqliteSeeder.php',
    'app/Infrastructure/Instances/RemoteInstanceTransferSource.php',
    'app/Infrastructure/Instances/RemoteProductionDeployment.php',
    'app/Infrastructure/Instances/RemoteProductionInstanceSourceLifecycle.php',
    'app/Infrastructure/Instances/RemoteProductionPhpRuntimeManager.php',
    'app/Infrastructure/Instances/RemoteProductionRepositoryBinding.php',
    'app/Infrastructure/Instances/RemoteProductionRouteApplicationUrlWriter.php',
    'app/Infrastructure/Instances/RemoteProductionWebRootManager.php',
    'app/Infrastructure/Instances/RemoteRegistrationSourceManager.php',
    'app/Infrastructure/Instances/YarnDependencyUpdatePresenceProgram.php',
    'app/Infrastructure/Metrics/MetricsCertificatePublisher.php',
    'app/Infrastructure/Nodes/CaddyPackageProgram.php',
    'app/Infrastructure/Nodes/MacOsNodeConverger.php',
    'app/Infrastructure/Nodes/NodeAgentSshExecutor.php',
    'app/Infrastructure/Nodes/NodeBootstrapCommandFactory.php',
    'app/Infrastructure/Nodes/NodeUpdateLock.php',
    'app/Infrastructure/Nodes/OsReleaseParserProgram.php',
    'app/Infrastructure/Nodes/RemoteNodeStorageRootPreparer.php',
    'app/Infrastructure/Nodes/RemotePhpPackageManager.php',
    'app/Infrastructure/Nodes/Roles/NodeRoleOperatingSystemGuard.php',
    'app/Infrastructure/Nodes/Roles/NodeRolePrerequisiteCommandFactory.php',
    'app/Infrastructure/Nodes/SshNodeCliInstaller.php',
    'app/Infrastructure/Processes/AnnotatorServerInstallation.php',
    'app/Infrastructure/Processes/RemoteProcessRuntimeManager.php',
    'app/Infrastructure/Processes/SystemdVpnOrderingDropIn.php',
    'app/Infrastructure/Projects/RemoteProjectUpdateSourceMutator.php',
    'app/Infrastructure/ProxyCli/ProxyCliCertificatePublisher.php',
    'app/Infrastructure/ProxyCli/ProxyCliSourcePublisher.php',
    'app/Infrastructure/Schedules/RemoteScheduleRuntimeManager.php',
    'app/Infrastructure/SharedOrbitDirectory.php',
    'app/Infrastructure/TaskVms/TaskVmGit.php',
    'app/Infrastructure/TaskVms/TaskVmRuntime.php',
    'app/Infrastructure/Tasks/GitHubTaskBaseBranchFetcher.php',
    'app/Infrastructure/Tasks/RemoteTaskCheckRunner.php',
    'app/Infrastructure/Tasks/RemoteTaskReviewDiff.php',
    'app/Infrastructure/Tasks/RemoteTaskWorkspaceDiffReader.php',
    'app/Infrastructure/Tasks/RemoteTaskWorkspaceMcp.php',
    'app/Infrastructure/Tasks/RemoteTaskWorkspaceSigner.php',
    'app/Infrastructure/Tools/ComposerToolManager.php',
    'app/Infrastructure/Tools/HomebrewMacCommand.php',
    'app/Infrastructure/Tools/HomebrewToolManager.php',
    'app/Infrastructure/Tools/VpToolManager.php',
    'app/Infrastructure/WebSocket/NativeWebSocketRuntimeLifecycle.php',
    'app/Infrastructure/WebSocket/WebSocketCertificatePublisher.php',
    'app/Infrastructure/WireGuard/NativeGatewayPeerProjectionManager.php',
    'app/Infrastructure/WireGuard/NativeGatewayVpnConverger.php',
    'app/Infrastructure/WireGuard/NativeWireGuardPeerConverger.php',
];

it('lists exactly the files that still hold an inline Node script', function (): void {
    $gateway = dirname(path: __DIR__, levels: 3);
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($gateway.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('/<<<[ \t]*([\'"]?)(?:BASH|PY|PYTHON|SH|SHELL|AWK)\1[ \t]*$/m', $contents) === 1) {
            $found[] = substr($file->getPathname(), strlen($gateway) + 1);
        }
    }

    sort($found);

    expect(array_values(array_diff($found, InlineRemoteScriptFiles)))->toBe([], 'Write a remote program under resources/remote instead of an inline script.')
        ->and(array_values(array_diff(InlineRemoteScriptFiles, $found)))->toBe([], 'Remove the migrated file from InlineRemoteScriptFiles.');
});

it('keeps the inline script list sorted and free of duplicates', function (): void {
    $sorted = InlineRemoteScriptFiles;
    sort($sorted);

    expect(InlineRemoteScriptFiles)->toBe(array_values(array_unique($sorted)));
});
