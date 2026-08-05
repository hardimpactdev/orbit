<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityListController;
use App\Http\Controllers\Api\ActivityShowController;
use App\Http\Controllers\Api\AnalyticsUpdateController;
use App\Http\Controllers\Api\AppAnalyticsController;
use App\Http\Controllers\Api\AppListController;
use App\Http\Controllers\Api\AppRegisterController;
use App\Http\Controllers\Api\AppRemoveController;
use App\Http\Controllers\Api\AppRootController;
use App\Http\Controllers\Api\AppRuntimeMountController;
use App\Http\Controllers\Api\AppSetupController;
use App\Http\Controllers\Api\AppSetupStepController;
use App\Http\Controllers\Api\AppShowController;
use App\Http\Controllers\Api\AppStoreController;
use App\Http\Controllers\Api\AppWebSocketController;
use App\Http\Controllers\Api\AppWorkerController;
use App\Http\Controllers\Api\CaRootController;
use App\Http\Controllers\Api\CloudflareController;
use App\Http\Controllers\Api\CodexAppController;
use App\Http\Controllers\Api\DashboardRuntimeInventoryController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionAddUserController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionAttachController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionDescribeController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionDestroyController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionDetachController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionIndexController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionQueryController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionSchemaController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionShowController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionStoreController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionTablesController;
use App\Http\Controllers\Api\DatabaseConnections\DatabaseConnectionUpdateController;
use App\Http\Controllers\Api\DeployController;
use App\Http\Controllers\Api\DoctorFixController;
use App\Http\Controllers\Api\DoctorRunController;
use App\Http\Controllers\Api\ExtensionDisableController;
use App\Http\Controllers\Api\ExtensionEnableController;
use App\Http\Controllers\Api\ExtensionListController;
use App\Http\Controllers\Api\FirewallRuleDestroyController;
use App\Http\Controllers\Api\FirewallRuleListController;
use App\Http\Controllers\Api\FirewallRuleStoreController;
use App\Http\Controllers\Api\GatewayStatusController;
use App\Http\Controllers\Api\InstanceController;
use App\Http\Controllers\Api\InstanceEnvController;
use App\Http\Controllers\Api\InternalExecutorTokenController;
use App\Http\Controllers\Api\ManifestSourceController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MetricsCredentialsController;
use App\Http\Controllers\Api\MetricsCredentialsResetController;
use App\Http\Controllers\Api\MetricsStatusController;
use App\Http\Controllers\Api\NodeBootstrapCompleteController;
use App\Http\Controllers\Api\NodeBootstrapController;
use App\Http\Controllers\Api\NodeBootstrapResumeController;
use App\Http\Controllers\Api\NodeGrantController;
use App\Http\Controllers\Api\NodeListController;
use App\Http\Controllers\Api\NodeManageController;
use App\Http\Controllers\Api\NodePermissionsController;
use App\Http\Controllers\Api\NodeRemoveController;
use App\Http\Controllers\Api\NodeRevokeController;
use App\Http\Controllers\Api\NodeRoleAddController;
use App\Http\Controllers\Api\NodeRoleListController;
use App\Http\Controllers\Api\NodeRoleRemoveController;
use App\Http\Controllers\Api\NodeShowController;
use App\Http\Controllers\Api\NodeStoreController;
use App\Http\Controllers\Api\NodeUpdateController;
use App\Http\Controllers\Api\OperationEventStreamController;
use App\Http\Controllers\Api\OperationStreamControlPlaneController;
use App\Http\Controllers\Api\PhpRuntimeController;
use App\Http\Controllers\Api\PhpUseController;
use App\Http\Controllers\Api\ProcessDestroyController;
use App\Http\Controllers\Api\ProcessListController;
use App\Http\Controllers\Api\ProcessLogController;
use App\Http\Controllers\Api\ProcessLogStreamStartController;
use App\Http\Controllers\Api\ProcessRestartController;
use App\Http\Controllers\Api\ProcessStartController;
use App\Http\Controllers\Api\ProcessStopController;
use App\Http\Controllers\Api\ProcessStoreController;
use App\Http\Controllers\Api\ProcessStreamController;
use App\Http\Controllers\Api\ProcessUpdateController;
use App\Http\Controllers\Api\ProxyRouteDestroyController;
use App\Http\Controllers\Api\ProxyRouteListController;
use App\Http\Controllers\Api\ProxyRouteStoreController;
use App\Http\Controllers\Api\RuntimeActivationController;
use App\Http\Controllers\Api\S3CredentialsController;
use App\Http\Controllers\Api\S3PublishController;
use App\Http\Controllers\Api\S3UnpublishController;
use App\Http\Controllers\Api\ScheduleDestroyController;
use App\Http\Controllers\Api\ScheduleListController;
use App\Http\Controllers\Api\ScheduleLogsController;
use App\Http\Controllers\Api\ScheduleRunController;
use App\Http\Controllers\Api\ScheduleShowController;
use App\Http\Controllers\Api\ScheduleStoreController;
use App\Http\Controllers\Api\SoloProxyController;
use App\Http\Controllers\Api\ToolCredentialsController;
use App\Http\Controllers\Api\ToolInstallController;
use App\Http\Controllers\Api\ToolLifecycleController;
use App\Http\Controllers\Api\ToolListController;
use App\Http\Controllers\Api\ToolLogsController;
use App\Http\Controllers\Api\ToolReconfigureController;
use App\Http\Controllers\Api\ToolRemoveController;
use App\Http\Controllers\Api\ToolShowController;
use App\Http\Controllers\Api\ToolUpdateBulkController;
use App\Http\Controllers\Api\ToolUpdateController;
use App\Http\Controllers\Api\UpdateAllController;
use App\Http\Controllers\Api\UpdateAllStartController;
use App\Http\Controllers\Api\UpdateArtifactDownloadController;
use App\Http\Controllers\Api\VpnClientCreateController;
use App\Http\Controllers\Api\VpnClientDisableController;
use App\Http\Controllers\Api\VpnClientEnableController;
use App\Http\Controllers\Api\VpnClientListController;
use App\Http\Controllers\Api\VpnClientRemoveController;
use App\Http\Controllers\Api\VpnWebUiChangePasswordController;
use App\Http\Controllers\Api\WorkspaceEnvController;
use App\Http\Controllers\Api\WorkspaceHistoryController;
use App\Http\Controllers\Api\WorkspaceListController;
use App\Http\Controllers\Api\WorkspaceLogController;
use App\Http\Controllers\Api\WorkspaceRemoveController;
use App\Http\Controllers\Api\WorkspaceSetupController;
use App\Http\Controllers\Api\WorkspaceShowController;
use App\Http\Controllers\Api\WorkspaceStepDeleteController;
use App\Http\Controllers\Api\WorkspaceStepListController;
use App\Http\Controllers\Api\WorkspaceStepStoreController;
use App\Http\Controllers\Api\WorkspaceStoreController;
use App\Http\Middleware\CorrelationHeader;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\RequireGatewayExtension;
use App\Http\Middleware\RequireGrantPermission;
use App\Http\Middleware\WireGuardIdentity;
use Illuminate\Support\Facades\Route;

Route::middleware(CorrelationHeader::class)->group(function (): void {
    Route::get('/status', GatewayStatusController::class)->name('api.status');
    Route::get('/ca/root', CaRootController::class);
    Route::get('/update/artifacts/{operationRun}/{artifactKind}/{platform}', UpdateArtifactDownloadController::class)
        ->whereIn('artifactKind', ['cli', 'agent'])
        ->name('api.update.artifacts.download');

    Route::middleware([WireGuardIdentity::class, RequireGrantPermission::class])->group(function (): void {
        Route::get('/operations/{operationRun}/events', OperationEventStreamController::class)
            ->name('api.operations.events');
        Route::get('/operations/{operationRun}/stream', [OperationStreamControlPlaneController::class, 'show'])
            ->name('api.operations.stream.descriptor');
        Route::post('/operations/{operationRun}/stream/auth', [OperationStreamControlPlaneController::class, 'auth'])
            ->name('api.operations.stream.auth');
        Route::post('/operations/{operationRun}/stream/leave', [OperationStreamControlPlaneController::class, 'leave'])
            ->name('api.operations.stream.leave');
        Route::get('/operations/{operationRun}/stream/stop-decision', [
            OperationStreamControlPlaneController::class,
            'stopDecision',
        ])
            ->name('api.operations.stream.stopDecision');
    });

    Route::middleware(WireGuardIdentity::class)->group(function (): void {
        Route::get('/runtime-activations/{type}/{id}', RuntimeActivationController::class)
            ->whereIn('type', ['app-instance', 'workspace'])
            ->whereNumber('id')
            ->name('api.runtime-activations.show');
        Route::post('/operations/{operationRun}/stream/publisher-credentials', [
            OperationStreamControlPlaneController::class,
            'publisherCredentials',
        ])
            ->name('api.operations.stream.publisherCredentials');
        Route::post('/operations/{operationRun}/stream/publish', [
            OperationStreamControlPlaneController::class,
            'publish',
        ])
            ->name('api.operations.stream.publish');
    });

    Route::middleware([
        WireGuardIdentity::class,
        RequireGrantPermission::class,
        LogActivity::class,
    ])->group(function (): void {
        Route::get('/activity', ActivityListController::class);
        Route::get('/activity/{id}', ActivityShowController::class);
        Route::post('/analytics/update', AnalyticsUpdateController::class);
        Route::get('/dashboard/runtime-inventory', DashboardRuntimeInventoryController::class);
        Route::middleware(RequireGatewayExtension::class.':cloudflare')->group(function (): void {
            Route::get('/cloudflare/zones', [CloudflareController::class, 'zones']);
            Route::get('/cloudflare/zones/{zone}/dns', [CloudflareController::class, 'dnsRecords']);
            Route::post('/cloudflare/zones/{zone}/dns', [CloudflareController::class, 'storeDnsRecord']);
            Route::delete('/cloudflare/zones/{zone}/dns/{record}', [CloudflareController::class, 'removeDnsRecord']);
            Route::post('/cloudflare/cache/flush', [CloudflareController::class, 'flushCache']);
            Route::post('/cloudflare/cache-rules/{app}', [CloudflareController::class, 'addCacheRule']);
            Route::delete('/cloudflare/cache-rules/{app}', [CloudflareController::class, 'removeCacheRule']);
            Route::put('/cloudflare/zones/{zone}/ssl', [CloudflareController::class, 'enableSsl']);
            Route::put('/cloudflare/zones/{zone}/ssl/disable', [CloudflareController::class, 'disableSsl']);
        });
        Route::post('/doctor/fix', DoctorFixController::class);
        Route::post('/doctor/run', DoctorRunController::class);
        Route::get('/extensions', ExtensionListController::class);
        Route::post('/extensions/{extension}/enable', ExtensionEnableController::class);
        Route::post('/extensions/{extension}/disable', ExtensionDisableController::class);
        Route::get('/database-connections', DatabaseConnectionIndexController::class);
        Route::post('/database-connections', DatabaseConnectionStoreController::class);
        Route::post('/database-connections/query', DatabaseConnectionQueryController::class);
        Route::get('/database-connections/tables', DatabaseConnectionTablesController::class);
        Route::get('/database-connections/schema', DatabaseConnectionSchemaController::class);
        Route::get('/database-connections/describe', DatabaseConnectionDescribeController::class);
        Route::get('/database-connections/{connection}', DatabaseConnectionShowController::class);
        Route::patch('/database-connections/{connection}', DatabaseConnectionUpdateController::class);
        Route::delete('/database-connections/{connection}', DatabaseConnectionDestroyController::class);
        Route::post('/database-connections/{connection}/users', DatabaseConnectionAddUserController::class);
        Route::post('/database-connections/{connection}/targets', DatabaseConnectionAttachController::class);
        Route::delete('/database-connections/{connection}/targets', DatabaseConnectionDetachController::class);
        Route::get('/deploy/history', [DeployController::class, 'history']);
        Route::get('/deploy/log/{run}', [DeployController::class, 'log']);
        Route::post('/deploy/run', [DeployController::class, 'run']);
        Route::get('/deploy/steps', [DeployController::class, 'listSteps']);
        Route::post('/deploy/steps', [DeployController::class, 'storeStep']);
        Route::delete('/deploy/steps/{step}', [DeployController::class, 'removeStep']);
        Route::post('/internal-executor/token/verify', InternalExecutorTokenController::class);
        Route::put('/manifest', [ManifestSourceController::class, 'update'])->name('api.manifest.update');
        Route::delete('/manifest', [ManifestSourceController::class, 'destroy'])->name('api.manifest.destroy');
        Route::get('/me', MeController::class);
        Route::get('/metrics/status', MetricsStatusController::class);
        Route::get('/metrics/credentials', MetricsCredentialsController::class);
        Route::post('/metrics/credentials/reset', MetricsCredentialsResetController::class);
        Route::get('/firewall-rules', FirewallRuleListController::class);
        Route::post('/firewall-rules', FirewallRuleStoreController::class);
        Route::delete('/firewall-rules/{name}', FirewallRuleDestroyController::class);
        Route::get('/processes', ProcessListController::class);
        Route::get('/processes/stream', ProcessStreamController::class)
            ->name('api.processes.stream');
        Route::post('/processes', ProcessStoreController::class);
        Route::post('/processes/restart', ProcessRestartController::class);
        Route::post('/processes/start', ProcessStartController::class);
        Route::post('/processes/stop', ProcessStopController::class);
        Route::post('/processes/{name}/log-stream', ProcessLogStreamStartController::class)
            ->name('api.processes.logStream.start');
        Route::get('/processes/{name}/log', ProcessLogController::class);
        Route::delete('/processes/{name}', ProcessDestroyController::class);
        Route::patch('/processes/{name}', ProcessUpdateController::class);
        Route::get('/php/runtime', PhpRuntimeController::class);
        Route::post('/php/use', PhpUseController::class);
        Route::get('/proxy-routes', ProxyRouteListController::class);
        Route::post('/proxy-routes', ProxyRouteStoreController::class);
        Route::delete('/proxy-routes/{domain}', ProxyRouteDestroyController::class);
        Route::get('/s3/credentials', S3CredentialsController::class);
        Route::post('/s3/public-hosts', S3PublishController::class);
        Route::delete('/s3/public-hosts/{host}', S3UnpublishController::class);
        Route::get('/schedules', ScheduleListController::class);
        Route::post('/schedules', ScheduleStoreController::class);
        Route::post('/schedules/{name}/run', ScheduleRunController::class);
        Route::get('/schedules/{name}/logs', ScheduleLogsController::class);
        Route::delete('/schedules/{name}', ScheduleDestroyController::class);
        Route::get('/schedules/{name}', ScheduleShowController::class);
        Route::get('/workspaces', WorkspaceListController::class);
        Route::post('/workspaces', WorkspaceStoreController::class);
        Route::post('/workspaces/setup', WorkspaceSetupController::class);
        Route::get('/workspaces/history/resolve-by-path', [WorkspaceHistoryController::class, 'fromPath']);
        Route::get('/workspaces/env/resolve-by-path', [WorkspaceEnvController::class, 'resolveByPath']);
        Route::get('/workspaces/runs/{run}/log', WorkspaceLogController::class);
        Route::get('/workspaces/steps/{phase}', WorkspaceStepListController::class);
        Route::post('/workspaces/steps/{phase}', WorkspaceStepStoreController::class);
        Route::delete('/workspaces/steps/{phase}/{step}', WorkspaceStepDeleteController::class);
        Route::get('/workspaces/resolve-by-path', [WorkspaceShowController::class, 'fromPath']);
        Route::get('/workspaces/{name}/history', WorkspaceHistoryController::class);
        Route::get('/workspaces/{workspace}/env', [WorkspaceEnvController::class, 'index']);
        Route::post('/workspaces/{workspace}/env', [WorkspaceEnvController::class, 'store']);
        Route::get('/workspaces/{workspace}/env/render', [WorkspaceEnvController::class, 'render']);
        Route::delete('/workspaces/{name}', WorkspaceRemoveController::class);
        Route::get('/workspaces/{name}', WorkspaceShowController::class);
        Route::get('/apps', AppListController::class);
        Route::post('/apps', AppStoreController::class);
        Route::post('/instances/register', AppRegisterController::class);
        Route::get('/instances', [InstanceController::class, 'all']);
        Route::middleware(RequireGatewayExtension::class.':codex')->group(function (): void {
            Route::get('/codex/apps', [CodexAppController::class, 'list']);
            Route::post('/codex/apps/{project}', [CodexAppController::class, 'add']);
            Route::delete('/codex/apps/{project}', [CodexAppController::class, 'remove']);
        });
        Route::middleware(RequireGatewayExtension::class.':solo')->group(function (): void {
            Route::get('/solo/tools', [SoloProxyController::class, 'tools']);
            Route::get('/solo/projects', [SoloProxyController::class, 'projects']);
            Route::get('/solo/{operation}', [SoloProxyController::class, 'read'])->where('operation', '.*');
            Route::match(
                ['POST', 'PUT', 'PATCH', 'DELETE'],
                '/solo/{operation}',
                [SoloProxyController::class, 'mutate'],
            )->where('operation', '.*');
        });
        Route::post('/instances/{instance}/analytics/enable', [AppAnalyticsController::class, 'enable']);
        Route::post('/instances/{instance}/analytics/disable', [AppAnalyticsController::class, 'disable']);
        Route::get('/instances/{instance}/analytics/verify', [AppAnalyticsController::class, 'verify']);
        Route::get('/instances/{instance}/analytics', [AppAnalyticsController::class, 'show']);
        Route::post('/instances/{instance}/root', AppRootController::class);
        Route::post('/instances/{instance}/setup', AppSetupController::class);
        Route::get('/instances/{instance}/setup-steps', [AppSetupStepController::class, 'index']);
        Route::post('/instances/{instance}/setup-steps', [AppSetupStepController::class, 'store']);
        Route::delete('/instances/{instance}/setup-steps/{step}', [AppSetupStepController::class, 'destroy']);
        Route::post('/instances/{instance}/websocket/enable', [AppWebSocketController::class, 'enable']);
        Route::post('/instances/{instance}/websocket/disable', [AppWebSocketController::class, 'disable']);
        Route::get('/instances/{instance}/websocket/credentials', [AppWebSocketController::class, 'credentials']);
        Route::get('/instances/{instance}/mounts', [AppRuntimeMountController::class, 'index']);
        Route::post('/instances/{instance}/mounts', [AppRuntimeMountController::class, 'store']);
        Route::delete('/instances/{instance}/mounts', [AppRuntimeMountController::class, 'destroy']);
        Route::get('/apps/{app}/instances', [InstanceController::class, 'index']);
        Route::post('/apps/{app}/instances', [InstanceController::class, 'store']);
        Route::get('/apps/{app}/instances/{instance}', [InstanceController::class, 'show']);
        Route::delete('/apps/{app}/instances/{instance}', [InstanceController::class, 'destroy']);
        Route::get('/apps/{app}/instances/{instance}/env', [InstanceEnvController::class, 'index']);
        Route::post('/apps/{app}/instances/{instance}/env', [InstanceEnvController::class, 'store']);
        Route::get('/apps/{app}/instances/{instance}/env/render', [InstanceEnvController::class, 'render']);
        Route::get('/instances/{instance}/worker', [AppWorkerController::class, 'show']);
        Route::post('/instances/{instance}/worker/enable', [AppWorkerController::class, 'enable']);
        Route::post('/instances/{instance}/worker/disable', [AppWorkerController::class, 'disable']);
        Route::delete('/apps/{app}', AppRemoveController::class);
        Route::get('/apps/{app}', AppShowController::class);
        Route::get('/tools', ToolListController::class);
        Route::delete('/tools/{tool}', ToolRemoveController::class);
        Route::post('/tools/{tool}/install', ToolInstallController::class);
        Route::post('/tools/{tool}/start', ToolLifecycleController::class)->defaults('action', 'start');
        Route::post('/tools/{tool}/stop', ToolLifecycleController::class)->defaults('action', 'stop');
        Route::post('/tools/{tool}/restart', ToolLifecycleController::class)->defaults('action', 'restart');
        Route::post('/tools/{tool}/reload', ToolLifecycleController::class)->defaults('action', 'reload');
        Route::get('/tools/{tool}/logs', ToolLogsController::class);
        Route::post('/tools/update', ToolUpdateBulkController::class);
        Route::post('/tools/{tool}/update', ToolUpdateController::class);
        Route::get('/tools/{tool}/credentials', ToolCredentialsController::class);
        Route::post('/tools/{tool}/reconfigure', ToolReconfigureController::class);
        Route::get('/tools/{tool}', ToolShowController::class);
        Route::post('/update/all/start', UpdateAllStartController::class)->name('api.update.all.start');
        Route::post('/update/all', UpdateAllController::class);
        Route::get('/vpn/clients', VpnClientListController::class);
        Route::post('/vpn/clients', VpnClientCreateController::class);
        Route::post('/vpn/clients/{name}/enable', VpnClientEnableController::class);
        Route::post('/vpn/clients/{name}/disable', VpnClientDisableController::class);
        Route::delete('/vpn/clients/{name}', VpnClientRemoveController::class);
        Route::post('/vpn/web-ui/password', VpnWebUiChangePasswordController::class);
        Route::get('/nodes', NodeListController::class);
        Route::post('/nodes/bootstrap/resume', NodeBootstrapResumeController::class);
        Route::post('/nodes/bootstrap', NodeBootstrapController::class);
        Route::post('/nodes/bootstrap/{nodeBootstrap}/complete', NodeBootstrapCompleteController::class);
        Route::post('/nodes', NodeStoreController::class);
        Route::post('/nodes/self/manage', [NodeManageController::class, 'manage']);
        Route::post('/nodes/grant', NodeGrantController::class);
        Route::post('/nodes/permissions', NodePermissionsController::class);
        Route::post('/nodes/revoke', NodeRevokeController::class);
        Route::get('/nodes/{name}/roles', NodeRoleListController::class);
        Route::post('/nodes/{name}/roles', NodeRoleAddController::class);
        Route::delete('/nodes/{name}/roles/{role}', NodeRoleRemoveController::class);
        Route::delete('/nodes/{name}', NodeRemoveController::class);
        Route::put('/nodes/{name}', NodeUpdateController::class);
        Route::get('/nodes/{name}', NodeShowController::class);
    });
});
