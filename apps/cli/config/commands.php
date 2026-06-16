<?php

declare(strict_types=1);

use App\Commands\Activity\ActivityListCommand;
use App\Commands\Activity\ActivityShowCommand;
use App\Commands\AgentIde\AgentIdeMessageCommand;
use App\Commands\App\AppAgentIdeCommand;
use App\Commands\App\AppListCommand;
use App\Commands\App\AppMountCommand;
use App\Commands\App\AppNewCommand;
use App\Commands\App\AppPruneCommand;
use App\Commands\App\AppRegisterCommand;
use App\Commands\App\AppRemoveCommand;
use App\Commands\App\AppRootCommand;
use App\Commands\App\AppShowCommand;
use App\Commands\App\AppWebSocketCredentialsCommand;
use App\Commands\App\AppWebSocketDisableCommand;
use App\Commands\App\AppWebSocketEnableCommand;
use App\Commands\App\AppWorkerCommand;
use App\Commands\Cloudflare\CfCacheFlushCommand;
use App\Commands\Cloudflare\CfCacheRuleAddCommand;
use App\Commands\Cloudflare\CfCacheRuleRemoveCommand;
use App\Commands\Cloudflare\CfDnsAddCommand;
use App\Commands\Cloudflare\CfDnsListCommand;
use App\Commands\Cloudflare\CfDnsRemoveCommand;
use App\Commands\Cloudflare\CfSslDisableCommand;
use App\Commands\Cloudflare\CfSslEnableCommand;
use App\Commands\Cloudflare\CfZoneListCommand;
use App\Commands\Database\DatabaseAddCommand;
use App\Commands\Database\DatabaseAttachCommand;
use App\Commands\Database\DatabaseDescribeCommand;
use App\Commands\Database\DatabaseDetachCommand;
use App\Commands\Database\DatabaseListCommand;
use App\Commands\Database\DatabaseQueryCommand;
use App\Commands\Database\DatabaseRemoveCommand;
use App\Commands\Database\DatabaseSchemaCommand;
use App\Commands\Database\DatabaseShowCommand;
use App\Commands\Database\DatabaseTablesCommand;
use App\Commands\Database\DatabaseUpdateCommand;
use App\Commands\Deploy\DeployHistoryCommand;
use App\Commands\Deploy\DeployLogCommand;
use App\Commands\Deploy\DeployRunCommand;
use App\Commands\Deploy\DeployStepAddCommand;
use App\Commands\Deploy\DeployStepListCommand;
use App\Commands\Deploy\DeployStepRemoveCommand;
use App\Commands\Dns\DnsListCommand;
use App\Commands\Dns\DnsResolveTldCommand;
use App\Commands\Firewall\FirewallAllowCommand;
use App\Commands\Firewall\FirewallDenyCommand;
use App\Commands\Firewall\FirewallListCommand;
use App\Commands\Firewall\FirewallRemoveCommand;
use App\Commands\Gateway\GatewayAddCommand;
use App\Commands\Gateway\GatewayListCommand;
use App\Commands\Gateway\GatewayTrustCommand;
use App\Commands\Gateway\GatewayUseCommand;
use App\Commands\GatewayStatusCommand;
use App\Commands\Internal\DatabaseQueryLocalCommand;
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
use App\Commands\Operation\DoctorCommand;
use App\Commands\Operation\ProfileCommand;
use App\Commands\Operation\UpdateAllCommand;
use App\Commands\Operation\UpdateCommand;
use App\Commands\Php\PhpListCommand;
use App\Commands\Php\PhpUseCommand;
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
use App\Commands\S3\S3CredentialsCommand;
use App\Commands\S3\S3PublishCommand;
use App\Commands\S3\S3UnpublishCommand;
use App\Commands\Schedule\ScheduleAddCommand;
use App\Commands\Schedule\ScheduleListCommand;
use App\Commands\Schedule\ScheduleLogsCommand;
use App\Commands\Schedule\ScheduleRemoveCommand;
use App\Commands\Schedule\ScheduleRunCommand;
use App\Commands\Schedule\ScheduleShowCommand;
use App\Commands\Tool\ToolCredentialsCommand;
use App\Commands\Tool\ToolInstallCommand;
use App\Commands\Tool\ToolListCommand;
use App\Commands\Tool\ToolReconfigureCommand;
use App\Commands\Tool\ToolRemoveCommand;
use App\Commands\Tool\ToolShowCommand;
use App\Commands\Tool\ToolUpdateCommand;
use App\Commands\Vpn\VpnClientDisableCommand;
use App\Commands\Vpn\VpnClientEnableCommand;
use App\Commands\Vpn\VpnClientListCommand;
use App\Commands\Vpn\VpnClientNewCommand;
use App\Commands\Vpn\VpnClientRemoveCommand;
use App\Commands\Vpn\VpnWebUiChangePasswordCommand;
use App\Commands\Workspace\WorkspaceHistoryCommand;
use App\Commands\Workspace\WorkspaceListCommand;
use App\Commands\Workspace\WorkspaceLogCommand;
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
        AppListCommand::class,
        AppMountCommand::class,
        AppNewCommand::class,
        AppPruneCommand::class,
        AppRegisterCommand::class,
        AppRemoveCommand::class,
        AppRootCommand::class,
        AppShowCommand::class,
        AppWebSocketCredentialsCommand::class,
        AppWebSocketDisableCommand::class,
        AppWebSocketEnableCommand::class,
        AppWorkerCommand::class,
        CfCacheFlushCommand::class,
        CfCacheRuleAddCommand::class,
        CfCacheRuleRemoveCommand::class,
        CfDnsAddCommand::class,
        CfDnsListCommand::class,
        CfDnsRemoveCommand::class,
        CfSslDisableCommand::class,
        CfSslEnableCommand::class,
        CfZoneListCommand::class,
        DatabaseAddCommand::class,
        DatabaseAttachCommand::class,
        DatabaseDetachCommand::class,
        DatabaseDescribeCommand::class,
        DatabaseListCommand::class,
        DatabaseQueryCommand::class,
        DatabaseRemoveCommand::class,
        DatabaseSchemaCommand::class,
        DatabaseShowCommand::class,
        DatabaseTablesCommand::class,
        DatabaseUpdateCommand::class,
        DnsListCommand::class,
        DnsResolveTldCommand::class,
        DeployHistoryCommand::class,
        DeployLogCommand::class,
        DeployRunCommand::class,
        DeployStepAddCommand::class,
        DeployStepListCommand::class,
        DeployStepRemoveCommand::class,
        FirewallAllowCommand::class,
        FirewallDenyCommand::class,
        FirewallListCommand::class,
        FirewallRemoveCommand::class,
        GatewayAddCommand::class,
        GatewayListCommand::class,
        GatewayTrustCommand::class,
        GatewayUseCommand::class,
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
        DoctorCommand::class,
        ProfileCommand::class,
        UpdateAllCommand::class,
        PhpListCommand::class,
        PhpUseCommand::class,
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
        S3CredentialsCommand::class,
        S3PublishCommand::class,
        S3UnpublishCommand::class,
        ScheduleAddCommand::class,
        ScheduleListCommand::class,
        ScheduleLogsCommand::class,
        ScheduleRemoveCommand::class,
        ScheduleRunCommand::class,
        ScheduleShowCommand::class,
        ToolCredentialsCommand::class,
        ToolInstallCommand::class,
        ToolListCommand::class,
        ToolReconfigureCommand::class,
        ToolRemoveCommand::class,
        ToolShowCommand::class,
        ToolUpdateCommand::class,
        VpnClientDisableCommand::class,
        VpnClientEnableCommand::class,
        VpnClientListCommand::class,
        VpnClientNewCommand::class,
        VpnClientRemoveCommand::class,
        VpnWebUiChangePasswordCommand::class,
        UpdateCommand::class,
        WorkspaceHistoryCommand::class,
        WorkspaceListCommand::class,
        WorkspaceLogCommand::class,
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
        DatabaseQueryLocalCommand::class,
        WorkspaceAdapterLookupCommand::class,
        WorkspaceAdapterUpdateCommand::class,
    ],

    'remove' => [
        //
    ],
];
