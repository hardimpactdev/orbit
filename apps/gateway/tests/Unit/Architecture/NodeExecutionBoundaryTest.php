<?php

declare(strict_types=1);

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\AgentCommands\AgentInvocation;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\RemotePrograms\SshProgramRunner;
use App\Infrastructure\Ssh\NativeSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\TaskUnitProgram;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;

/** @return list<string> */
function node_execution_dependencies(string $source): array
{
    $names = [
        ProcessRunner::class,
        NativeProcessRunner::class,
        ProcessInvocation::class,
        SshExecutor::class,
        NativeSshExecutor::class,
        RemoteCommand::class,
        DevelopmentSshExecutor::class,
        RemoteProgramRunner::class,
        SshProgramRunner::class,
        RemoteProgramCatalog::class,
        TaskUnitProgram::class,
        AgentTransport::class,
        AgentTransports::class,
        AgentInvocation::class,
        Process::class,
        Illuminate\Support\Facades\Process::class,
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec',
    ];
    // The bare name Process also names the Process model, so the Symfony and Laravel classes match by namespace.
    $tokens = array_map(static fn (string $name): string => preg_quote(basename(str_replace('\\', '/', $name)), '/'), array_diff($names, [Process::class, Illuminate\Support\Facades\Process::class]));
    $pattern = '/\b(?:'.implode('|', $tokens).')\b|(?:Component|Facades)\\\\+(?:Process|\{)|`/i';
    $code = implode('', array_map(static fn (array|string $token): string => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token, token_get_all($source)));
    if (preg_match($pattern, $code) !== 1) {
        return [];
    }
    $forbidden = array_fill_keys(array_map(strtolower(...), $names), true);
    static $parser = null;
    $parser ??= new ParserFactory()->createForNewestSupportedVersion();
    $nodes = new NodeTraverser(new NameResolver)->traverse($parser->parse($source) ?? []);
    $dependencies = [];
    foreach (new NodeFinder()->find($nodes, static fn (Node $node): bool => $node instanceof Node\Name || $node instanceof Node\Scalar\String_ || $node instanceof Node\Expr\ShellExec) as $node) {
        if ($node instanceof Node\Expr\ShellExec) {
            $dependencies[] = 'shell backticks';

            continue;
        }
        $name = $node instanceof Node\Name ? $node->toString() : $node->value;
        if ($node instanceof Node\Scalar\String_ && ! str_contains($name, '\\')) {
            continue;
        }
        if (isset($forbidden[strtolower(ltrim($name, '\\'))])) {
            $dependencies[] = $name;
        }
    }

    return array_values(array_unique($dependencies));
}

it('recognizes execution dependencies through aliases strings functions and shell syntax', function (string $source): void {
    expect(node_execution_dependencies('<?php namespace App\\Example; '.$source))->not->toBeEmpty();
})->with([
    'alias' => 'use App\\Infrastructure\\Processes\\ProcessRunner as Runner; function run(Runner $runner) {}',
    'group import' => 'use App\\Infrastructure\\Ssh\\{RemoteCommand}; new RemoteCommand([]);',
    'fully qualified' => 'new \\Symfony\\Component\\Process\\Process([]);',
    'facade' => 'use Illuminate\\Support\\Facades\\Process; Process::run([]);',
    'grouped facade' => 'use Illuminate\\Support\\Facades\\{Log, Process}; Process::run([]);',
    'grouped component' => 'use Symfony\\Component\\{Process\\Process}; new Process([]);',
    'namespace import' => 'use Symfony\\Component\\Process; new Process\\Process([]);',
    'class string' => '$class = "Symfony\\\\Component\\\\Process\\\\Process"; new $class([]);',
    'container string' => 'app("App\\\\Infrastructure\\\\Ssh\\\\SshExecutor");',
    'function' => 'proc_open("command", [], $pipes);',
    'qualified function' => '\\shell_exec("command");',
    'backticks' => '`command`;',
]);

it('does not confuse model names method calls comments or data with process execution', function (string $source): void {
    expect(node_execution_dependencies('<?php namespace App\\Example; '.$source))->toBe([]);
})->with([
    'model' => 'use App\\Models\\Process; Process::query();',
    'PDO method' => '$database->exec("SELECT 1");',
    'comment' => '// `shell_exec` is forbidden',
    'data label' => '$labels = ["system" => "exec"];',
]);

it('keeps Node execution inside the agent transports across core and extensions', function (): void {
    $allowed = [
        // The shared process and SSH transports, their wiring, and the one command runner.
        'app/Providers/Domains/SshServiceProvider.php',
        'app/Providers/Domains/AppDevServiceProvider.php',
        'app/Providers/Domains/GatewayServiceProvider.php',
        'app/Providers/Domains/CertificatesServiceProvider.php',
        'app/Providers/Domains/ProcessesServiceProvider.php',
        'app/Providers/Domains/SourceControlServiceProvider.php',
        'app/Providers/Domains/AgentViewServiceProvider.php',
        'app/Providers/Domains/RemoteProgramsServiceProvider.php',
        'app/Providers/Domains/FleetServiceProvider.php',
        'app/Providers/Domains/AgentCommandsServiceProvider.php',
        'app/Providers/GatewayReleasesServiceProvider.php',
        'app/Infrastructure/Processes/ProcessRunner.php',
        'app/Infrastructure/Processes/NativeProcessRunner.php',
        'app/Infrastructure/Processes/LockRenewingProcessRunner.php',
        'app/Infrastructure/Ssh/SshExecutor.php',
        'app/Infrastructure/Ssh/NativeSshExecutor.php',
        'app/Infrastructure/AgentCommands/AgentInvocation.php',
        // Uses only AgentInvocation::wire to validate the exact serialized command input.
        'app/Infrastructure/AgentCommands/AgentSchemas.php',
        'app/Infrastructure/AgentCommands/AgentOutputDecoder.php',
        'app/Infrastructure/AgentCommands/IncusAgentTransport.php',
        'app/Infrastructure/AgentCommands/AgentTransports.php',
        'app/Infrastructure/AgentCommands/NativeAgentCommandRunner.php',
        'app/Infrastructure/AgentCommands/LocalAgentTransport.php',
        'app/Infrastructure/AgentCommands/SshAgentTransport.php',
        'app/Infrastructure/AgentCommands/WorkspaceAgentTransport.php',
        'app/Infrastructure/AgentCommands/WorkspaceAgentCommandRunner.php',
        'app/Infrastructure/TaskVms/TaskVmCredentialGuard.php',
        // Gateway control-plane work: signing, local mirrors, artifact inspection, and Artisan children.
        'app/Actions/Gateway/BootstrapGatewayAction.php',
        'app/Infrastructure/Certificates/OpenSslGatewayCertificateIssuer.php',
        'app/Infrastructure/Certificates/OpenSslGatewayCertificateValidator.php',
        'app/Infrastructure/Certificates/OpenSslLeafCertificateSigner.php',
        'app/Infrastructure/SourceControl/GitRemoteBranchHeads.php',
        'app/Infrastructure/SourceControl/NativeRepositoryDefaultBranchResolver.php',
        'app/Infrastructure/Fleet/GitReleaseHistory.php',
        'app/Infrastructure/GatewayReleases/GatewayReleaseAgentDelivery.php',
        'app/Infrastructure/TaskVms/TaskVmGit.php',
        'app/Infrastructure/TaskVms/UpCloudGuest.php',
        'app/Actions/TaskVms/ObservePackageCacheAction.php',
        'app/Infrastructure/GitHub/ProcessGitHubCliToken.php',
        'app/Infrastructure/MachineSoftware/AuthenticatedUbuntuSnapshot.php',
        'app/Infrastructure/MachineSoftware/UbuntuReleaseSignature.php',
        'app/Infrastructure/MachineSoftware/RecipeArtifactsBump.php',
        'app/Infrastructure/GatewayReleases/ScriptGatewayReleaseSmoke.php',
        'app/Infrastructure/AgentView/ProcessAgentViewPublisher.php',
        'app/Jobs/RunScheduledCommand.php',
        // Command rendering for image/topology tooling; these classes do not execute the rendered commands.
        'app/Console/Commands/RenderNodeProgramsCommand.php',
        'app/Infrastructure/Nodes/NodeEnrollmentPrograms.php',
        'app/Infrastructure/Nodes/RemotePhpPackageManager.php',
        // The source lifecycle supplies the SSH callback to the workspace command runner for Git import.
        'app/Infrastructure/Instances/RemoteDevelopmentInstanceSourceLifecycle.php',
        // Private DNS is excluded from this port; the remaining raw calls in these classes serve it.
        'app/Infrastructure/AppDev/DnsmasqPrivateDnsManager.php',
        'app/Infrastructure/Gateway/GatewayPrivateDnsResolver.php',
        'app/Infrastructure/Nodes/Roles/GatewayRoleBaseline.php',
        'app/Infrastructure/Doctor/NativeRoleStateInspector.php',
        'app/Infrastructure/WireGuard/NativeGatewayVpnConverger.php',
        // The Services-only Operation primitive invokes names from the existing closed program catalog.
        'app/Domain/Operations/SwampOperationExecutor.php',
        // Existing Tasks workspace execution and its journal are excluded from this port.
        'app/Infrastructure/AppDev/DevelopmentSshExecutor.php',
        'app/Infrastructure/RemotePrograms/SshProgramRunner.php',
        'app/Infrastructure/RemotePrograms/RemoteProgramCatalog.php',
        'app/Domain/Tasks/TaskWorkspaceLeaseRecovery.php',
        'app/Infrastructure/Tasks/GitHubTaskBaseBranchFetcher.php',
        'app/Infrastructure/Tasks/GitHubTaskPullRequestPublisher.php',
        'app/Infrastructure/Tasks/NativeDeliverablePathRepository.php',
        'app/Infrastructure/Tasks/RemoteTaskCheckRunner.php',
        'app/Infrastructure/Tasks/RemoteTaskReviewDiff.php',
        'app/Infrastructure/Tasks/RemoteTaskReviewReset.php',
        'app/Infrastructure/Tasks/RemoteTaskTurnReceipts.php',
        'app/Infrastructure/Tasks/RemoteTaskWorkspaceDiffReader.php',
        'app/Infrastructure/Tasks/RemoteTaskWorkspaceMcp.php',
        'app/Infrastructure/Tasks/RemoteTaskWorkspaceSigner.php',
        'app/Infrastructure/Tasks/RemoteTaskWorkspaceStateReader.php',
        'app/Infrastructure/Tasks/RemoteWorkspaceOperationJournal.php',
        'app/Infrastructure/Tasks/TaskWorkspaceExecutor.php',
    ];
    $violations = [];
    $unusedExceptions = array_fill_keys($allowed, true);
    $gateway = dirname(__DIR__, 3);
    foreach (['app', 'routes', 'bootstrap', 'config', 'database', 'extensions'] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($gateway.'/'.$root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = substr($file->getPathname(), strlen($gateway) + 1);
            if (str_contains($path, '/tests/') || str_starts_with($path, 'bootstrap/cache/')) {
                continue;
            }
            $dependencies = node_execution_dependencies(file_get_contents($file->getPathname()));
            if (in_array($path, $allowed, true)) {
                if ($dependencies !== []) {
                    unset($unusedExceptions[$path]);
                }

                continue;
            }
            if ($dependencies !== []) {
                $violations[$path] = $dependencies;
            }
        }
    }

    expect($violations)->toBe([])
        ->and(array_keys($unusedExceptions))->toBe([]);
});
