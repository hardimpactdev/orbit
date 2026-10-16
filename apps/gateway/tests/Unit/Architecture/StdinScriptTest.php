<?php

declare(strict_types=1);

/*
 * A shell whose flags hold `s`, such as `bash -s` or `bash -seu`, reads its script from stdin while it runs it. A child
 * that reads stdin, such as `incus exec` without `-n`, takes the rest of the script, and the shell then exits 0 having
 * run only its start. The role image transfer lost every copy this way.
 *
 * These are the files that pipe a script into such a shell. Review a new one, then list it here: its script must run
 * no child that reads stdin, or it goes as the argument of `bash -c` instead, as `TaskVmScripts::bash()` passes it.
 */
const StdinShellFiles = [
    'app/Infrastructure/Analytics/AnalyticsCertificatePublisher.php',
    'app/Infrastructure/Analytics/NativeAnalyticsPublicationManager.php',
    'app/Infrastructure/AppDev/DnsmasqPrivateDnsManager.php',
    'app/Infrastructure/AppDev/RemoteAppDevCertificateManager.php',
    'app/Infrastructure/AppDev/RemoteAppDevPhpFpmManager.php',
    'app/Infrastructure/AppDev/RemoteAppDevRouteFirewallManager.php',
    'app/Infrastructure/AppDev/RemoteAppDevTldRouteManager.php',
    'app/Infrastructure/Caddy/Build/NodeCaddyLiveReader.php',
    'app/Infrastructure/Caddy/Build/NodeCaddyPushScript.php',
    'app/Infrastructure/Doctor/NativeCustomProxyRouteInspector.php',
    'app/Infrastructure/Doctor/NativeInstanceStateInspector.php',
    'app/Infrastructure/Doctor/NativePrivateRouteProjectionInspector.php',
    'app/Infrastructure/Doctor/NativeProjectStateInspector.php',
    'app/Infrastructure/Doctor/NativePublicRouteEdgeInspector.php',
    'app/Infrastructure/Doctor/NativeRoleStateInspector.php',
    'app/Infrastructure/Doctor/NativeRouteApplicationUrlInspector.php',
    'app/Infrastructure/Doctor/NativeScheduleStateInspector.php',
    'app/Infrastructure/Doctor/SshNodeStateInspector.php',
    'app/Infrastructure/Fleet/Footprint/TmpfilesFootprintArtifact.php',
    'app/Infrastructure/Gateway/GatewayCheckoutAccessConverger.php',
    'app/Infrastructure/Gateway/GatewayPrivateDnsResolver.php',
    'app/Infrastructure/Gateway/GatewayWebDirectoryConverger.php',
    'app/Infrastructure/Gateway/NativeGatewayCertificatePublisher.php',
    'app/Infrastructure/Gateway/NativeGatewayFpmConverger.php',
    'app/Infrastructure/GatewayReleases/GatewayNodeAgentUpdate.php',
    'app/Infrastructure/Hibernation/RemoteHibernationMarkerStore.php',
    'app/Infrastructure/Hibernation/RemoteInstanceCheckoutInspector.php',
    'app/Infrastructure/Instances/DevelopmentCaddyAccessCommand.php',
    'app/Infrastructure/Instances/RemoteDevelopmentDeployment.php',
    'app/Infrastructure/Instances/RemoteDevelopmentInstanceConfigurator.php',
    'app/Infrastructure/Instances/RemoteDevelopmentInstanceSourceLifecycle.php',
    'app/Infrastructure/Instances/RemoteInstanceCloneCandidateInspector.php',
    'app/Infrastructure/Instances/RemoteInstanceDestinationGuard.php',
    'app/Infrastructure/Instances/RemoteInstanceLogReader.php',
    'app/Infrastructure/Instances/RemoteInstanceTransferSource.php',
    'app/Infrastructure/Instances/RemoteProductionDeployment.php',
    'app/Infrastructure/Instances/RemoteProductionInstanceSourceLifecycle.php',
    'app/Infrastructure/Instances/RemoteProductionPhpRuntimeManager.php',
    'app/Infrastructure/Instances/RemoteProductionRepositoryBinding.php',
    'app/Infrastructure/Instances/RemoteProductionWebRootManager.php',
    'app/Infrastructure/Metrics/MetricsCertificatePublisher.php',
    'app/Infrastructure/Metrics/NativeMetricsAccessRevoker.php',
    'app/Infrastructure/Nodes/CaddyPackageProgram.php',
    'app/Infrastructure/Nodes/MacOsNodeConverger.php',
    'app/Infrastructure/Nodes/NodeAgentSshExecutor.php',
    'app/Infrastructure/Nodes/NodeBootstrapCommandFactory.php',
    'app/Infrastructure/Nodes/NodeUpdateLock.php',
    'app/Infrastructure/Nodes/RemoteNodeStorageRootPreparer.php',
    'app/Infrastructure/Nodes/RemotePhpPackageManager.php',
    'app/Infrastructure/Nodes/Roles/DevelopmentRoleBaseline.php',
    'app/Infrastructure/Nodes/Roles/NodeRoleOperatingSystemGuard.php',
    'app/Infrastructure/Nodes/Roles/NodeRolePrerequisiteCommandFactory.php',
    'app/Infrastructure/Nodes/SshNodeCliInstaller.php',
    'app/Infrastructure/Processes/RemoteProcessRuntimeManager.php',
    'app/Infrastructure/Processes/SystemdVpnOrderingDropIn.php',
    'app/Infrastructure/Projects/RemoteProjectUpdateSourceMutator.php',
    'app/Infrastructure/ProxyCli/ProxyCliCertificatePublisher.php',
    'app/Infrastructure/ProxyCli/ProxyCliSourcePublisher.php',
    'app/Infrastructure/RemotePrograms/SshProgramRunner.php',
    'app/Infrastructure/Schedules/RemoteScheduleRuntimeManager.php',
    'app/Infrastructure/TaskVms/IncusTaskVmImageBuilder.php',
    'app/Infrastructure/TaskVms/TaskVmSetupScript.php',
    'app/Infrastructure/TaskVms/UpCloudTaskVmImageBuilder.php',
    'app/Infrastructure/Tasks/GitHubTaskBaseBranchFetcher.php',
    'app/Infrastructure/Tasks/NativeDeliverablePathRepository.php',
    'app/Infrastructure/Tasks/RemoteTaskCheckRunner.php',
    'app/Infrastructure/Tasks/RemoteTaskReviewDiff.php',
    'app/Infrastructure/Tasks/RemoteTaskTurnReceipts.php',
    'app/Infrastructure/Tasks/RemoteTaskWorkspaceDiffReader.php',
    'app/Infrastructure/Tasks/RemoteTaskWorkspaceMcp.php',
    'app/Infrastructure/Tasks/RemoteTaskWorkspaceSigner.php',
    'app/Infrastructure/Tasks/RemoteTaskWorkspaceStateReader.php',
    'app/Infrastructure/Tools/ComposerToolManager.php',
    'app/Infrastructure/Tools/HomebrewMacCommand.php',
    'app/Infrastructure/Tools/HomebrewToolManager.php',
    'app/Infrastructure/Tools/VpToolManager.php',
    'app/Infrastructure/WebSocket/NativeWebSocketRuntimeLifecycle.php',
    'app/Infrastructure/WebSocket/WebSocketCertificatePublisher.php',
    'app/Infrastructure/WireGuard/NativeGatewayPeerProjectionManager.php',
    'app/Infrastructure/WireGuard/NativeGatewayVpnConverger.php',
    'app/Infrastructure/WireGuard/NativeWireGuardPeerConverger.php',
    'app/Infrastructure/WireGuard/NativeWireGuardPeerDnsRepairer.php',
];

/** The resource scripts that the files above pipe by name. Each closes stdin in main(), which Bash reads whole first. */
const StdinShellResources = ['task-vms/base-image-clean.sh', 'task-vms/hub.sh', 'task-vms/incus-host.sh'];

/**
 * Children that read the stdin of every script: `incus exec` without `-n`, `incus file push -`, and `ssh` without `-n`.
 * The scripts name `incus --project P` as `"${incus[@]}"`.
 */
const StdinChildren = [
    '/\b(?:incus|lxc)\b[^\n;|&]*?\sexec\b(?![^\n;|&]*\s(?:-n|--disable-stdin)\b)/',
    '/\b(?:incus|lxc)\b[^\n;|&]*?\sfile\s+push\s+(?:[^\n;|&]*\s)?-(?=\s|$)/m',
    '/(?<![\w.\/-])ssh\s+(?!(?:[^\n;|&]*\s)?-[a-zA-Z]*n[a-zA-Z]*\b)/',
];

/** Also never in a script on stdin: `docker exec -i`, and `read` from stdin. */
const StdinReaders = [
    ...StdinChildren,
    '/\bdocker\s+(?:container\s+)?exec\b(?=[^\n;|&]*\s-[a-zA-Z]*i)/',
    '/(?<![\w.$-])read\s(?![^\n;|]*<)/',
];

/** A path below the Gateway. */
function stdin_shell_path(string $path = ''): string
{
    return dirname(path: __DIR__, levels: 3).'/'.$path;
}

/**
 * The commands of a shell script: line continuations joined, PHP's `\$` unescaped, and comments and quoted strings
 * dropped. A `read` that a `while` loop redirects, or that a pipe feeds, reads no stdin of the script.
 */
function stdin_shell_commands(string $script): string
{
    $script = str_replace(['\$', "\\\n", '"${incus[@]}"'], ['$', ' ', 'incus'], $script);
    $script = (string) preg_replace(['/^\s*#.*$/m', '/\'[^\'\n]*\'|"(?:[^"\\\\\n]|\\\\.)*"/'], ['', "''"], $script);
    $script = (string) preg_replace('/\|\s*while\b([^\n]*)\bread\b/', '| while$1READ', $script);

    return (string) preg_replace('/\bwhile\b([^\n]*)\bread\b(.*?\bdone\s*<)/s', 'while$1READ$2', $script);
}

/** The bodies of the heredocs and nowdocs of a PHP file, which hold its inline scripts. */
function stdin_shell_heredocs(string $path): string
{
    preg_match_all('/<<<[ \t]*([\'"]?)([A-Za-z_]\w*)\1\n(.*?)\n[ \t]*\2\b/s', (string) file_get_contents(stdin_shell_path($path)), $heredocs);

    return implode("\n", $heredocs[3]);
}

/**
 * @param  list<string>  $patterns
 * @return list<string> what the patterns find in the script
 */
function stdin_shell_matches(string $script, array $patterns): array
{
    $found = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, stdin_shell_commands($script), $matches) > 0) {
            array_push($found, ...$matches[0]);
        }
    }

    return $found;
}

/** @return list<string> the paths, below the Gateway, of the files with `$extension` in `$directories` */
function stdin_shell_files(array $directories, string $extension): array
{
    $found = [];
    foreach ($directories as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(stdin_shell_path($directory), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === $extension) {
                $found[] = substr($file->getPathname(), strlen(stdin_shell_path()));
            }
        }
    }
    sort($found);

    return $found;
}

it('lists exactly the files that pipe a script into a shell', function (): void {
    $found = array_values(array_filter(
        stdin_shell_files(['app', 'extensions'], 'php'),
        static fn (string $path): bool => preg_match('/[\'"](?:\/usr)?(?:\/bin\/)?(?:ba)?sh[\'"](?:\s*,\s*[\'"]-[a-zA-Z]+[\'"])*?\s*,\s*[\'"]-[a-zA-Z]*s[a-zA-Z]*[\'"]/', (string) file_get_contents(stdin_shell_path($path))) === 1,
    ));

    expect(array_values(array_diff($found, StdinShellFiles)))->toBe([], 'Pass the script as the argument of `bash -c` (TaskVmScripts::bash()), or review it and list it in StdinShellFiles.')
        ->and(array_values(array_diff(StdinShellFiles, $found)))->toBe([], 'Remove the file that no longer pipes a script from StdinShellFiles.');
});

it('keeps the list sorted and free of duplicates', function (): void {
    $sorted = StdinShellFiles;
    sort($sorted);

    expect(StdinShellFiles)->toBe(array_values(array_unique($sorted)));
});

it('runs no child that reads stdin in an inline script of a file that pipes scripts into a shell', function (string $path): void {
    expect(stdin_shell_matches(stdin_shell_heredocs($path), StdinReaders))->toBe([]);
})->with(StdinShellFiles);

it('closes stdin in main() of every resource script that goes into a shell on stdin', function (string $name): void {
    expect((string) file_get_contents(stdin_shell_path('resources/'.$name)))
        ->toMatch('/^main\(\) \{\n    exec [^\n]*<\/dev\/null\n/m')
        ->toEndWith("\nmain \"\$@\"\n");
})->with(StdinShellResources);

it('runs no child that reads stdin in a resource shell script', function (string $path): void {
    expect(stdin_shell_matches((string) file_get_contents(stdin_shell_path($path)), StdinReaders))->toBe([]);
})->with(stdin_shell_files(['resources'], 'sh'));

it('disables stdin for incus exec and ssh in every inline script, which a piped script may embed', function (): void {
    $found = [];
    foreach (stdin_shell_files(['app', 'extensions'], 'php') as $path) {
        foreach (stdin_shell_matches(stdin_shell_heredocs($path), StdinChildren) as $match) {
            $found[] = "{$path}: {$match}";
        }
    }

    expect($found)->toBe([]);
});

it('closes the stdin of the role image transfer for every child', function (): void {
    expect((string) file_get_contents(stdin_shell_path('resources/task-vms/role-images-transfer.sh')))->toMatch('/^exec <\/dev\/null$/m');
});

it('catches the children that read stdin', function (string $command, bool $reads): void {
    expect(stdin_shell_matches($command, StdinReaders) !== [])->toBe($reads);
})->with([
    ['"${incus[@]}" exec "$vm" -- install -d /x', true],
    ['"${incus[@]}" exec -n "$vm" -- install -d /x', false],
    ['incus --project orbit-tasks exec tvm-1 -- true', true],
    ['incus -q exec tvm-1 -- true', true],
    ["incus exec \\\n    tvm-1 -- true", true],
    ['incus exec --disable-stdin tvm-1 -- true', false],
    ['incus file push - tvm-1/etc/x', true],
    ['incus file push /tmp/x tvm-1/etc/x', false],
    ['ssh host uptime', true],
    ['ssh -n host uptime', false],
    ['ssh -o BatchMode=yes -n host uptime', false],
    ['ssh -nT host uptime', false],
    ['ssh-keygen -l -f key.pub', false],
    ['docker exec -i app php -v', true],
    ['docker container exec -i app mysql', true],
    ['docker exec app php -v', false],
    ['read -r line', true],
    ['IFS=. read -r -a octets <<<"$range"', false],
    ["while IFS= read -r path; do\n  echo \"\$path\"\ndone <\"\$list\"", false],
    ['printf "%s\n" a | while read -r line; do :; done', false],
    ["printf 'Caddy cannot read %s\\n' x", false],
    ['# read the rest', false],
]);
