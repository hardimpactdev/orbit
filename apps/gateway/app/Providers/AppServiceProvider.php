<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ConvergesAppRuntimeContainers;
use App\Contracts\PhpRuntimeArtifactConverger;
use App\Contracts\ProgressReporter;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Contracts\StartsRemoteShellProcesses;
use App\Contracts\ToolDefinition;
use App\Contracts\UpdateAllGatewayStream;
use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Ca\OrbitCaService;
use App\Services\Ca\OrbitSiteCertificateInstaller;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\LocalResolver;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Dns\OrbitDnsServiceInstaller;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use App\Services\Doctor\DnsRuntimeProbe;
use App\Services\Doctor\NodeDnsProjectionProbe;
use App\Services\Doctor\ProxyDnsProjectionProbe;
use App\Services\Gateway\SdkUpdateAllGatewayStream;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use App\Services\Nodes\WireGuardSelfRouteOutput;
use App\Services\Operations\OperationResultRegistry;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Operations\OperationTokenIntrospector;
use App\Services\Php\AgentPushPhpRuntimeArtifactConverger;
use App\Services\Processes\PhpProcessStreamConnection;
use App\Services\Processes\ProcessRuntimeWakeConcurrentRunner;
use App\Services\Processes\ProcessStreamClock;
use App\Services\Processes\ProcessStreamConnection;
use App\Services\Processes\ProcessStreamRuntimeConfig;
use App\Services\Processes\ProcessStreamSleeper;
use App\Services\Processes\RuntimeWakeConcurrentRunner;
use App\Services\Processes\SystemProcessStreamClock;
use App\Services\Processes\UsleepProcessStreamSleeper;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteHostExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Solo\HttpSoloUpstreamClient;
use App\Services\Solo\SoloUpstreamClient;
use App\Services\Tools\ToolDefinitionRegistry;
use App\Services\Trust\LinuxTrustStoreInstaller;
use App\Services\Trust\MacOsTrustStoreInstaller;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Updates\UnattendedUpgradesDriver;
use App\Services\Updates\UpdateDriverRegistry;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\Vpn\VpnDnsSwarmManager;
use App\Services\Vpn\VpnNodeResolver;
use App\Services\Vpn\WgEasyServiceInstaller;
use App\Services\Vpn\WgEasyVpnBackend;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use App\Support\LocalPlatform;
use App\Support\OpenApi\GatewayOpenApi;
use App\Support\Streaming\NullProgressReporter;
use App\Tools\AntigravityCliTool;
use App\Tools\CaddyTool;
use App\Tools\ClaudeCodeTool;
use App\Tools\CodexAppTool;
use App\Tools\CodexCliTool;
use App\Tools\ComposerTool;
use App\Tools\CursorCliTool;
use App\Tools\DnsTool;
use App\Tools\DockerTool;
use App\Tools\GhTool;
use App\Tools\GitTool;
use App\Tools\GrokCliTool;
use App\Tools\HermesTool;
use App\Tools\LaravelInstallerTool;
use App\Tools\MailpitTool;
use App\Tools\NodeExporterTool;
use App\Tools\OrbStackTool;
use App\Tools\PhpCliTool;
use App\Tools\PhpTool;
use App\Tools\SeaweedfsTool;
use App\Tools\VitePlusTool;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use Orbit\Sdk\Laravel\GatewayConnector;
use Override;
use RuntimeException;
use Spatie\LaravelData\Support\DataConfig;

/**
 * @mago-expect lint:too-many-methods
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @mago-expect lint:halstead
     */
    #[Override]
    public function register(): void
    {
        $_SERVER['PAO_DISABLE'] ??= '1';

        Relation::morphMap([
            \App\Models\App::class => App::class,
        ]);

        $this->app->scoped(ActivityLogCorrelation::class);
        $this->app->scoped(WebSocketRoleBaselineTiming::class);
        $this->app->singleton(OperationResultRegistry::class);
        $this->app->afterResolving(DataConfig::class, function (DataConfig $config): void {
            $config->enforceMorphMap([
                'orbit_instance_driver_config' => OrbitInstanceDriverConfigData::class,
                'laravel_cloud_instance_driver_config' => LaravelCloudInstanceDriverConfigData::class,
            ]);
        });

        $this->app->bind(OperationTokenFactory::class, fn (Application $app): OperationTokenFactory => new OperationTokenFactory(
            signer: $app->make(OperationTokenSigner::class),
            secret: $this->operationTokenSigningKey(),
            ttlSeconds: $this->operationTokenTtlSeconds(),
            keyId: $this->operationTokenSigningKeyId(),
        ));
        $this->app->bind(OperationTokenIntrospector::class, fn (Application $app): OperationTokenIntrospector => new OperationTokenIntrospector(
            verifier: $app->make(OperationTokenVerifier::class),
            secretsByKeyId: $this->operationTokenSecretsByKeyId(),
            notBeforeSkewSeconds: $this->operationTokenNotBeforeSkewSeconds(),
        ));
        $this->app->singleton(GatewayConnector::class, static function (Application $app): GatewayConnector {
            $settings = LocalGatewaySettings::current();

            return new GatewayConnector(
                baseUrl: is_string($settings->gateway_url) ? $settings->gateway_url : null,
                caPemPath: $settings->ca_pem_path,
                correlationIdResolver: static function () use ($app): ?string {
                    $correlation = $app->make(ActivityLogCorrelation::class);

                    return $correlation->current();
                },
            );
        });
        $this->app->singleton(LocalResolver::class);
        $this->app->bind(ProgressReporter::class, NullProgressReporter::class);
        $this->app->singleton(ProcessStreamRuntimeConfig::class);
        $this->app->bind(ProcessStreamSleeper::class, UsleepProcessStreamSleeper::class);
        $this->app->bind(ProcessStreamClock::class, SystemProcessStreamClock::class);
        $this->app->bind(ProcessStreamConnection::class, PhpProcessStreamConnection::class);
        $this->app->bind(
            RuntimeWakeConcurrentRunner::class,
            fn (Application $app): RuntimeWakeConcurrentRunner => new ProcessRuntimeWakeConcurrentRunner(
                forceSequential: $app->runningUnitTests(),
            ),
        );
        $this->app->bind(PhpRuntimeArtifactConverger::class, AgentPushPhpRuntimeArtifactConverger::class);
        $this->app->bind(RemoteExecutor::class, RemoteHostExecutor::class);
        $this->app->bind(RemoteShell::class, RemoteHostExecutor::class);
        $this->app->bind(StartsRemoteShellProcesses::class, RemoteHostExecutor::class);
        $this->app->bind(RunsInternalCommands::class, RemoteLocalExecutor::class);
        $this->app->bind(RemoteLocalExecutor::class, fn (Application $app): RemoteLocalExecutor => new RemoteLocalExecutor(
            commands: $app->make(LocalExecutorCommandBuilder::class),
            operationTokens: $app->make(OperationTokenFactory::class),
            activityLogger: $app->make(ActivityLogger::class),
            operationRuns: $app->make(OperationRunRecorder::class),
            applicationKey: $this->applicationKey(),
        ));
        $this->app->bind(NodeWireGuardSelfRouteProbe::class, fn (Application $app): NodeWireGuardSelfRouteProbe => new NodeWireGuardSelfRouteProbe(
            localExecutor: $app->make(RemoteLocalExecutor::class),
            routeOutput: $app->make(WireGuardSelfRouteOutput::class),
        ));
        $this->app->bind(AppRuntimeContainerManager::class, function (Application $app): AppRuntimeContainerManager {
            $commands = $app->make(DockerCommandBuilder::class);
            $ca = $app->make(OrbitCaService::class);
            $innerTlsPolicy = $app->make(AppDevelopmentInnerTlsPolicy::class);
            $nodeHostPaths = $app->make(NodeHostPaths::class);
            $localExecutor = $this->hasOperationTokenSigningKey() ? $app->make(RunsInternalCommands::class) : null;

            return new AppRuntimeContainerManager(
                commands: $commands,
                ca: $ca,
                innerTlsPolicy: $innerTlsPolicy,
                nodeHostPaths: $nodeHostPaths,
                localExecutor: $localExecutor,
            );
        });
        $this->app->bind(ConvergesAppRuntimeContainers::class, AppRuntimeContainerManager::class);
        $this->app->bind(WorkspaceRuntimeContainerManager::class, function (Application $app): WorkspaceRuntimeContainerManager {
            $commands = $app->make(DockerCommandBuilder::class);
            $ca = $app->make(OrbitCaService::class);
            $innerTlsPolicy = $app->make(AppDevelopmentInnerTlsPolicy::class);
            $nodeHostPaths = $app->make(NodeHostPaths::class);
            $localExecutor = $this->hasOperationTokenSigningKey() ? $app->make(RunsInternalCommands::class) : null;

            return new WorkspaceRuntimeContainerManager(
                commands: $commands,
                ca: $ca,
                innerTlsPolicy: $innerTlsPolicy,
                nodeHostPaths: $nodeHostPaths,
                localExecutor: $localExecutor,
            );
        });
        $this->app->bind(SiteCertificateInstaller::class, OrbitSiteCertificateInstaller::class);
        $this->app->bind(SoloUpstreamClient::class, HttpSoloUpstreamClient::class);
        $this->app->bind(UpdateAllGatewayStream::class, SdkUpdateAllGatewayStream::class);
        $this->app->singleton(
            ToolDefinitionRegistry::class,
            static function (Application $app): ToolDefinitionRegistry {
                /** @var list<ToolDefinition> $definitions */
                $definitions = [
                    $app->make(CaddyTool::class),
                    $app->make(DockerTool::class),
                    $app->make(OrbStackTool::class),
                    $app->make(VitePlusTool::class),
                    $app->make(PhpCliTool::class),
                    $app->make(GhTool::class),
                    $app->make(GitTool::class),
                    $app->make(ComposerTool::class),
                    $app->make(DnsTool::class),
                    $app->make(PhpTool::class),
                    $app->make(MailpitTool::class),
                    $app->make(SeaweedfsTool::class),
                    $app->make(NodeExporterTool::class),
                    $app->make(OrbStackTool::class),
                    $app->make(HermesTool::class),
                    $app->make(LaravelInstallerTool::class),
                    $app->make(CodexAppTool::class),
                    $app->make(ClaudeCodeTool::class),
                    $app->make(CodexCliTool::class),
                    $app->make(GrokCliTool::class),
                    $app->make(AntigravityCliTool::class),
                    $app->make(CursorCliTool::class),
                ];

                return new ToolDefinitionRegistry($definitions);
            },
        );
        $this->app->singleton(
            UpdateDriverRegistry::class,
            fn (Application $app): UpdateDriverRegistry => new UpdateDriverRegistry([
                $app->make(UnattendedUpgradesDriver::class),
            ]),
        );

        $this->app->bind(WgEasyVpnBackend::class, fn (Application $app): WgEasyVpnBackend => new WgEasyVpnBackend(
            username: (string) config('services.wg_easy.username', config('orbit.wg_easy.username', 'orbit')),
            password: (string) config('services.wg_easy.password', config('orbit.wg_easy.password', '')),
            databasePath: $this->orbitConfigPath().'/wg-easy/wg-easy.db',
            localExecutor: $this->hasOperationTokenSigningKey() ? $app->make(RemoteLocalExecutor::class) : null,
            vpnNodeResolver: $app->make(VpnNodeResolver::class),
        ));

        $this->app->singleton(WgEasyServiceInstaller::class, fn (Application $app): WgEasyServiceInstaller => new WgEasyServiceInstaller(
            rootPath: $this->orbitConfigPath(),
            statePath: $this->wgEasyStatePath(),
            localExecutor: $this->hasOperationTokenSigningKey() ? $app->make(RemoteLocalExecutor::class) : null,
            vpnNodeResolver: $app->make(VpnNodeResolver::class),
        ));

        $this->app->singleton(VpnDnsSwarmInstaller::class, fn (Application $app): VpnDnsSwarmInstaller => new VpnDnsSwarmInstaller(
            rootPath: $this->orbitConfigPath(),
            statePath: $this->orbitConfigPath().'/wg-easy',
            reconciler: $app->make(DnsmasqReconciler::class),
            localExecutor: $this->hasOperationTokenSigningKey() ? $app->make(RemoteLocalExecutor::class) : null,
            vpnNodeResolver: $app->make(VpnNodeResolver::class),
        ));

        $this->app->singleton(OrbitDnsServiceInstaller::class, fn (Application $app): OrbitDnsServiceInstaller => new OrbitDnsServiceInstaller(
            reconciler: $app->make(DnsmasqReconciler::class),
            rootPath: $this->orbitConfigPath(),
        ));

        $this->app->singleton(DnsmasqReconciler::class, fn (Application $app): DnsmasqReconciler => new DnsmasqReconciler(
            baseConfigBuilder: $app->make(DnsmasqBaseConfigBuilder::class),
            nodeRecordsBuilder: $app->make(NodeDnsmasqRecordsBuilder::class),
            proxyRecordsBuilder: $app->make(ProxyDnsmasqRecordsBuilder::class),
            rootPath: $this->orbitConfigPath(),
            swarmManager: $app->make(VpnDnsSwarmManager::class),
        ));

        $this->app->singleton(DnsRuntimeProbe::class, fn (Application $app): DnsRuntimeProbe => new DnsRuntimeProbe(
            baseConfigBuilder: $app->make(DnsmasqBaseConfigBuilder::class),
            rootPath: $this->orbitConfigPath(),
            swarmManager: $app->make(VpnDnsSwarmManager::class),
            dnsmasqReconciler: $app->make(DnsmasqReconciler::class),
        ));

        $this->app->singleton(NodeDnsProjectionProbe::class, fn (Application $app): NodeDnsProjectionProbe => new NodeDnsProjectionProbe(
            recordsBuilder: $app->make(NodeDnsmasqRecordsBuilder::class),
            rootPath: $this->orbitConfigPath(),
        ));

        $this->app->singleton(ProxyDnsProjectionProbe::class, fn (Application $app): ProxyDnsProjectionProbe => new ProxyDnsProjectionProbe(
            recordsBuilder: $app->make(ProxyDnsmasqRecordsBuilder::class),
            rootPath: $this->orbitConfigPath(),
        ));

        $this->app->bind(TrustStoreInstaller::class, static function (Application $app): TrustStoreInstaller {
            $platform = $app->make(LocalPlatform::class);

            return match ($platform->current()) {
                'macos' => new MacOsTrustStoreInstaller,
                'linux' => new LinuxTrustStoreInstaller,
                default => throw new RuntimeException('Unsupported platform for trust store operations.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(base_path('database/migrations'));

        GatewayOpenApi::register();
    }

    private function orbitConfigPath(): string
    {
        $configured = config('orbit.paths.config_root');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            $home = '/root';
        }

        return $home.'/.config/orbit';
    }

    private function operationTokenSigningKey(): string
    {
        $secret = $this->nonEmptyConfigString('orbit.operation_token_secret');

        if ($secret !== null) {
            return $secret;
        }

        $secret = $this->nonEmptyConfigString('app.key');

        if ($secret === null) {
            throw new RuntimeException('Operation token signing secret is not configured.');
        }

        return $secret;
    }

    private function hasOperationTokenSigningKey(): bool
    {
        if ($this->nonEmptyConfigString('orbit.operation_token_secret') !== null) {
            return true;
        }

        return $this->nonEmptyConfigString('app.key') !== null;
    }

    private function operationTokenSigningKeyId(): string
    {
        $dedicatedSecret = $this->nonEmptyConfigString('orbit.operation_token_secret');

        if ($dedicatedSecret !== null) {
            $keyId = $this->nonEmptyConfigString('orbit.operation_token_key_id', trimResult: true);

            if ($keyId !== null) {
                return $keyId;
            }

            return 'current';
        }

        return 'app-key';
    }

    /**
     * @return array<string, string>
     */
    private function operationTokenSecretsByKeyId(): array
    {
        $currentKeyId = $this->operationTokenSigningKeyId();
        $secrets = [
            $currentKeyId => $this->operationTokenSigningKey(),
        ];

        $previousKeyId = $this->nonEmptyConfigString('orbit.operation_token_previous_key_id', trimResult: true);
        $previousSecret = $this->nonEmptyConfigString('orbit.operation_token_previous_secret');

        if ($previousKeyId !== null && $previousSecret !== null) {
            $secrets[$previousKeyId] = $previousSecret;
        }

        $dedicatedSecret = $this->nonEmptyConfigString('orbit.operation_token_secret');
        $appKey = $this->nonEmptyConfigString('app.key');

        if (
            $dedicatedSecret !== null
            && $appKey !== null
            && $currentKeyId !== 'app-key'
        ) {
            $secrets['app-key'] = $appKey;
        }

        return $secrets;
    }

    private function operationTokenNotBeforeSkewSeconds(): int
    {
        $skewSeconds = config('orbit.operation_token_not_before_skew_seconds');

        if (is_int($skewSeconds)) {
            return max(0, $skewSeconds);
        }

        if (is_string($skewSeconds) && ctype_digit($skewSeconds)) {
            return (int) $skewSeconds;
        }

        return 5;
    }

    private function applicationKey(): string
    {
        $secret = $this->nonEmptyConfigString('app.key');

        if ($secret === null) {
            throw new RuntimeException('Application key is not configured.');
        }

        return $secret;
    }

    private function operationTokenTtlSeconds(): int
    {
        $ttlSeconds = config('orbit.operation_token_ttl_seconds');

        if (is_int($ttlSeconds)) {
            return $this->validateOperationTokenTtlSeconds($ttlSeconds);
        }

        if (is_string($ttlSeconds) && ctype_digit($ttlSeconds)) {
            return $this->validateOperationTokenTtlSeconds((int) $ttlSeconds);
        }

        throw new RuntimeException('Operation token TTL is not configured.');
    }

    private function validateOperationTokenTtlSeconds(int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new RuntimeException('Operation token TTL is not configured.');
        }

        return $ttlSeconds;
    }

    private function nonEmptyConfigString(string $key, bool $trimResult = false): ?string
    {
        try {
            $value = config()->string($key);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (trim($value) === '') {
            return null;
        }

        return $trimResult ? trim($value) : $value;
    }

    private function wgEasyStatePath(): string
    {
        $databasePath = config('services.wg_easy.database_path');

        if (is_string($databasePath) && $databasePath !== '') {
            return rtrim(dirname($databasePath), '/');
        }

        return '/home/orbit/.wg-easy';
    }
}
