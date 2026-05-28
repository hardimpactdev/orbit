<?php

declare(strict_types=1);

use App\Commands\Activity\ActivityListCommand;
use App\Commands\Activity\ActivityShowCommand;
use App\Commands\AgentIde\AgentIdeMessageCommand;
use App\Commands\App\AppAgentIdeCommand;
use App\Commands\App\AppExecCommand;
use App\Commands\App\AppListCommand;
use App\Commands\App\AppNewCommand;
use App\Commands\App\AppPruneCommand;
use App\Commands\App\AppRegisterCommand;
use App\Commands\App\AppRemoveCommand;
use App\Commands\App\AppRootCommand;
use App\Commands\App\AppShowCommand;
use App\Commands\App\AppWorkerCommand;
use App\Commands\Cloudflare\CfDnsListCommand;
use App\Commands\Cloudflare\CfZoneListCommand;
use App\Commands\Database\DatabaseDescribeCommand;
use App\Commands\Database\DatabaseListCommand;
use App\Commands\Database\DatabaseSchemaCommand;
use App\Commands\Database\DatabaseShowCommand;
use App\Commands\Database\DatabaseTablesCommand;
use App\Commands\Deploy\DeployHistoryCommand;
use App\Commands\Deploy\DeployStepListCommand;
use App\Commands\Dns\DnsListCommand;
use App\Commands\Dns\DnsResolveTldCommand;
use App\Commands\Firewall\FirewallListCommand;
use App\Commands\Gateway\GatewayAddCommand;
use App\Commands\Gateway\GatewayTrustCommand;
use App\Commands\GatewayStatusCommand;
use App\Commands\Internal\VerifyExecutorCommand;
use App\Commands\Internal\WgEasyStateCommand;
use App\Commands\Internal\WorkspaceAdapterLookupCommand;
use App\Commands\Internal\WorkspaceAdapterUpdateCommand;
use App\Commands\Node\NodeAgentIdeCommand;
use App\Commands\Node\NodeDefaultCommand;
use App\Commands\Node\NodeGrantCommand;
use App\Commands\Node\NodeListCommand;
use App\Commands\Node\NodeNewCommand;
use App\Commands\Node\NodePermissionsCommand;
use App\Commands\Node\NodeRemoveCommand;
use App\Commands\Node\NodeRevokeCommand;
use App\Commands\Node\NodeRoleAddCommand;
use App\Commands\Node\NodeRoleListCommand;
use App\Commands\Node\NodeRoleRemoveCommand;
use App\Commands\Node\NodeShowCommand;
use App\Commands\Node\NodeUpdateCommand;
use App\Commands\Operation\UpdateCommand;
use App\Commands\Php\PhpListCommand;
use App\Commands\Process\ProcessAddCommand;
use App\Commands\Process\ProcessEditCommand;
use App\Commands\Process\ProcessListCommand;
use App\Commands\Process\ProcessLogsCommand;
use App\Commands\Process\ProcessRemoveCommand;
use App\Commands\Process\ProcessRestartCommand;
use App\Commands\Process\ProcessStartCommand;
use App\Commands\Process\ProcessStopCommand;
use App\Commands\Proxy\ProxyAddCommand;
use App\Commands\Proxy\ProxyListCommand;
use App\Commands\Proxy\ProxyRemoveCommand;
use App\Commands\Schedule\ScheduleAddCommand;
use App\Commands\Schedule\ScheduleListCommand;
use App\Commands\Schedule\ScheduleLogsCommand;
use App\Commands\Schedule\ScheduleRemoveCommand;
use App\Commands\Schedule\ScheduleRunCommand;
use App\Commands\Schedule\ScheduleShowCommand;
use App\Commands\Tool\ToolCredentialsCommand;
use App\Commands\Tool\ToolInstallCommand;
use App\Commands\Tool\ToolListCommand;
use App\Commands\Tool\ToolLogsCommand;
use App\Commands\Tool\ToolReconfigureCommand;
use App\Commands\Tool\ToolReloadCommand;
use App\Commands\Tool\ToolRemoveCommand;
use App\Commands\Tool\ToolRestartCommand;
use App\Commands\Tool\ToolShowCommand;
use App\Commands\Tool\ToolStartCommand;
use App\Commands\Tool\ToolStopCommand;
use App\Commands\Tool\ToolUpdateCommand;
use App\Commands\Workspace\WorkspaceExecCommand;
use App\Commands\Workspace\WorkspaceHistoryCommand;
use App\Commands\Workspace\WorkspaceListCommand;
use App\Commands\Workspace\WorkspaceNewCommand;
use App\Commands\Workspace\WorkspaceRemoveCommand;
use App\Commands\Workspace\WorkspaceSetupCommand;
use App\Commands\Workspace\WorkspaceSetupStepAddCommand;
use App\Commands\Workspace\WorkspaceSetupStepListCommand;
use App\Commands\Workspace\WorkspaceSetupStepRemoveCommand;
use App\Commands\Workspace\WorkspaceShowCommand;
use App\Commands\Workspace\WorkspaceTeardownStepAddCommand;
use App\Commands\Workspace\WorkspaceTeardownStepListCommand;
use App\Commands\Workspace\WorkspaceTeardownStepRemoveCommand;
use Illuminate\Console\Scheduling\ScheduleFinishCommand;
use Illuminate\Console\Scheduling\ScheduleListCommand as FrameworkScheduleListCommand;
use Illuminate\Foundation\Console\VendorPublishCommand;
use LaravelZero\Framework\Commands\BuildCommand;
use LaravelZero\Framework\Commands\InstallCommand;
use LaravelZero\Framework\Commands\MakeCommand;
use LaravelZero\Framework\Commands\RenameCommand;
use LaravelZero\Framework\Commands\StubPublishCommand;
use LaravelZero\Framework\Commands\TestMakeCommand;
use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\ListCommand;

return [
    'default' => SummaryCommand::class,

    // Disable path discovery entirely. Every command must be registered explicitly
    // in 'add' (public) or 'hidden' (internal/framework) below.
    'paths' => [],

    // Explicit product command registration. Internal commands go in 'hidden'.
    'add' => [
        ActivityListCommand::class,
        ActivityShowCommand::class,
        AgentIdeMessageCommand::class,
        AppAgentIdeCommand::class,
        AppExecCommand::class,
        AppListCommand::class,
        AppNewCommand::class,
        AppPruneCommand::class,
        AppRegisterCommand::class,
        AppRemoveCommand::class,
        AppRootCommand::class,
        AppShowCommand::class,
        AppWorkerCommand::class,
        CfDnsListCommand::class,
        CfZoneListCommand::class,
        DatabaseDescribeCommand::class,
        DatabaseListCommand::class,
        DatabaseSchemaCommand::class,
        DatabaseShowCommand::class,
        DatabaseTablesCommand::class,
        DnsListCommand::class,
        DnsResolveTldCommand::class,
        DeployHistoryCommand::class,
        DeployStepListCommand::class,
        FirewallListCommand::class,
        GatewayAddCommand::class,
        GatewayTrustCommand::class,
        GatewayStatusCommand::class,
        NodeAgentIdeCommand::class,
        NodeDefaultCommand::class,
        NodeGrantCommand::class,
        NodeListCommand::class,
        NodeNewCommand::class,
        NodePermissionsCommand::class,
        NodeRemoveCommand::class,
        NodeRevokeCommand::class,
        NodeRoleAddCommand::class,
        NodeRoleListCommand::class,
        NodeRoleRemoveCommand::class,
        NodeShowCommand::class,
        NodeUpdateCommand::class,
        PhpListCommand::class,
        ProcessAddCommand::class,
        ProcessEditCommand::class,
        ProcessListCommand::class,
        ProcessLogsCommand::class,
        ProcessRemoveCommand::class,
        ProcessRestartCommand::class,
        ProcessStartCommand::class,
        ProcessStopCommand::class,
        ProxyAddCommand::class,
        ProxyListCommand::class,
        ProxyRemoveCommand::class,
        ScheduleAddCommand::class,
        ScheduleListCommand::class,
        ScheduleLogsCommand::class,
        ScheduleRemoveCommand::class,
        ScheduleRunCommand::class,
        ScheduleShowCommand::class,
        ToolCredentialsCommand::class,
        ToolInstallCommand::class,
        ToolListCommand::class,
        ToolLogsCommand::class,
        ToolReconfigureCommand::class,
        ToolReloadCommand::class,
        ToolRemoveCommand::class,
        ToolRestartCommand::class,
        ToolShowCommand::class,
        ToolStartCommand::class,
        ToolStopCommand::class,
        ToolUpdateCommand::class,
        UpdateCommand::class,
        WorkspaceExecCommand::class,
        WorkspaceHistoryCommand::class,
        WorkspaceListCommand::class,
        WorkspaceNewCommand::class,
        WorkspaceRemoveCommand::class,
        WorkspaceSetupCommand::class,
        WorkspaceSetupStepAddCommand::class,
        WorkspaceSetupStepListCommand::class,
        WorkspaceSetupStepRemoveCommand::class,
        WorkspaceShowCommand::class,
        WorkspaceTeardownStepAddCommand::class,
        WorkspaceTeardownStepListCommand::class,
        WorkspaceTeardownStepRemoveCommand::class,
    ],

    'hidden' => [
        // Framework / vendor commands hidden from normal output.
        SummaryCommand::class,
        DumpCompletionCommand::class,
        HelpCommand::class,
        ListCommand::class,
        FrameworkScheduleListCommand::class,
        ScheduleFinishCommand::class,
        VendorPublishCommand::class,
        StubPublishCommand::class,
        BuildCommand::class,
        InstallCommand::class,
        MakeCommand::class,
        RenameCommand::class,
        TestCommand::class,
        TestMakeCommand::class, // LaravelZero\Framework\Commands\TestMakeCommand

        // Internal executor commands — always hidden from public CLI output.
        VerifyExecutorCommand::class,
        WgEasyStateCommand::class,
        WorkspaceAdapterLookupCommand::class,
        WorkspaceAdapterUpdateCommand::class,
    ],

    'remove' => [
        //
    ],
];
