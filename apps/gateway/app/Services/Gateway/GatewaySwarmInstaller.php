<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Enums\Gateway\GatewayExposureMode;
use App\Services\Ca\OrbitCaService;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Tools\CaddyTool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/** @mago-expect lint:kan-defect */
class GatewaySwarmInstaller
{
    private const string GatewayCertPath = '/etc/orbit/certs/gateway.crt';

    private const string GatewayKeyPath = '/etc/orbit/certs/gateway.key';

    public function __construct(
        private readonly OrbitCaService $caService = new OrbitCaService,
        private readonly GatewaySwarmManager $swarm = new GatewaySwarmManager,
        private readonly GatewaySwarmStackRenderer $stackRenderer = new GatewaySwarmStackRenderer,
        private readonly GatewayImageAcquirer $imageAcquirer = new GatewayImageAcquirer,
        private readonly GatewayDirectFirewallInstaller $directFirewall = new GatewayDirectFirewallInstaller,
        private readonly GatewayExposureTransitionGuard $transitionGuard = new GatewayExposureTransitionGuard,
        private readonly CaddyTool $caddyTool = new CaddyTool,
        private readonly GatewayCaddyRouteRenderer $gatewayRouteRenderer = new GatewayCaddyRouteRenderer,
        private readonly GatewayTlsKeyModeRepairer $tlsKeyModeRepairer = new GatewayTlsKeyModeRepairer,
    ) {}

    public function install(
        string $wireguardAddress,
        GatewayImageReference $image,
        GatewayExposureMode $exposureMode,
        ?string $configRoot = null,
        string $wireguardCidr = '10.6.0.0/24',
        string $wireguardInterface = 'wg-orbit',
        ?string $imageArchive = null,
    ): void {
        if (filter_var($wireguardAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("Invalid WireGuard API address: {$wireguardAddress}");
        }

        $configRoot = $this->configRoot($configRoot);

        File::ensureDirectoryExists("{$configRoot}/certs", 0700);
        $this->bootstrapConfigRoot($configRoot);

        $gatewayLeaf = $this->issueAndStageGatewayLeaf($wireguardAddress);
        $this->imageAcquirer->ensure($image, $imageArchive);

        if ($exposureMode->isGatewayDirect()) {
            $this->directFirewall->install($wireguardCidr, $wireguardInterface);
        }

        $this->swarm->ensureSwarm();
        $this->swarm->ensureGatewayNodeLabel();
        $this->swarm->ensureAttachableOverlayNetwork();

        if ($exposureMode->isGatewayDirect()) {
            $this->transitionGuard->assertPublicPortsReleased();
        }

        $stackPath = $this->swarm->writeStackFile(
            $this->stackRenderer->render($image, $exposureMode, $configRoot),
        );

        $this->swarm->deployStack($stackPath);

        if ($exposureMode->isRouterColocated()) {
            $this->transitionGuard->assertPublicPortsReleased();
            $this->serveGatewayLeafViaRouterCaddy(
                wireguardAddress: $wireguardAddress,
                wireguardCidr: $wireguardCidr,
                gatewayLeaf: $gatewayLeaf,
                browserHostname: GatewayLeafIdentity::browserHostname(),
                ensureCaddyContainer: true,
            );
        }
    }

    public function bootstrapRuntimeConfig(?string $configRoot = null): void
    {
        $this->bootstrapConfigRoot($this->configRoot($configRoot));
    }

    /**
     * Issue or reissue the gateway API leaf so required SANs are present, then
     * install router-facing TLS artifacts when exposure is router-colocated.
     *
     * Used by fleet update convergence; install() shares the same leaf and
     * serve helpers. Router container recreation is skipped here because the
     * public orbit-caddy already owns host ports on a running gateway.
     *
     * @return array{cert: string, key: string}
     */
    public function convergeGatewayLeafServing(
        string $wireguardAddress,
        GatewayExposureMode $exposureMode,
        ?string $configRoot = null,
        string $wireguardCidr = '10.6.0.0/24',
    ): array {
        if (filter_var($wireguardAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("Invalid WireGuard API address: {$wireguardAddress}");
        }

        $configRoot = $this->configRoot($configRoot);
        File::ensureDirectoryExists("{$configRoot}/certs", 0700);

        $gatewayLeaf = $this->issueAndStageGatewayLeaf($wireguardAddress);

        if ($exposureMode->isRouterColocated()) {
            $this->serveGatewayLeafViaRouterCaddy(
                wireguardAddress: $wireguardAddress,
                wireguardCidr: $wireguardCidr,
                gatewayLeaf: $gatewayLeaf,
                browserHostname: GatewayLeafIdentity::browserHostname(),
                ensureCaddyContainer: false,
            );
        }

        return $gatewayLeaf;
    }

    /**
     * @return array{cert: string, key: string}
     */
    private function issueAndStageGatewayLeaf(string $wireguardAddress): array
    {
        $gatewayLeaf = $this->caService->issueLeaf(
            GatewayLeafIdentity::ShortHost,
            GatewayLeafIdentity::additionalSansForShortHost($wireguardAddress),
        );
        File::chmod($gatewayLeaf['cert'], 0o644);
        File::chmod($gatewayLeaf['key'], 0o600);

        return $gatewayLeaf;
    }

    /**
     * @param  array{cert: string, key: string}  $gatewayLeaf
     */
    private function serveGatewayLeafViaRouterCaddy(
        string $wireguardAddress,
        string $wireguardCidr,
        array $gatewayLeaf,
        string $browserHostname,
        bool $ensureCaddyContainer,
    ): void {
        $this->installCaddyReadableGatewayLeaf($gatewayLeaf);

        if ($ensureCaddyContainer) {
            $container = OrbitCaddyContainer::forPublicIngress($wireguardAddress);

            $this->runShellScript(
                $this->caddyTool->updateScript(['container' => $container->spec()]),
                'converge router-owned orbit-caddy container',
            );
        }

        $this->runRequired(
            'sudo install -d -m 0755 '.escapeshellarg($this->hostFsPath('/etc/caddy/orbit')),
            'prepare router-owned gateway Caddy include directory',
        );
        $this->runRequiredWithInput(
            'sudo tee '.escapeshellarg($this->hostFsPath('/etc/caddy/orbit/orbit-gateway.caddy')).' > /dev/null',
            $this->gatewayRouteRenderer->render(
                serverNames: [$wireguardAddress, $browserHostname, ':443'],
                wireguardCidr: $wireguardCidr,
                // Paths inside the orbit-caddy container, not the gateway host-path mount.
                certPath: self::GatewayCertPath,
                keyPath: self::GatewayKeyPath,
            ),
            'write router-colocated gateway Caddy route',
        );

        $this->assertRouterCaddyCanReadGatewayLeaf();

        $this->runRequired(CaddyTool::reloadCommand('orbit-caddy'), 'reload router-owned orbit-caddy container');
    }

    private function assertRouterCaddyCanReadGatewayLeaf(): void
    {
        $this->runRequired(
            sprintf('docker exec %s test -r %s', escapeshellarg('orbit-caddy'), escapeshellarg(self::GatewayCertPath)),
            'verify router-owned orbit-caddy can read gateway certificate',
        );
        $this->runRequired(
            sprintf('docker exec %s test -r %s', escapeshellarg('orbit-caddy'), escapeshellarg(self::GatewayKeyPath)),
            'verify router-owned orbit-caddy can read gateway certificate key',
        );
    }

    /**
     * @param  array{cert: string, key: string}  $leaf
     */
    private function installCaddyReadableGatewayLeaf(array $leaf): void
    {
        $hostCertPath = $this->hostFsPath(self::GatewayCertPath);
        $hostKeyPath = $this->hostFsPath(self::GatewayKeyPath);

        $this->runRequired(
            'sudo install -d -m 0755 '.escapeshellarg(dirname($hostCertPath)),
            'prepare Orbit Caddy certificate directory',
        );
        $this->runRequired(
            sprintf('sudo install -m 0644 %s %s', escapeshellarg($leaf['cert']), escapeshellarg($hostCertPath)),
            'install gateway certificate for router-owned orbit-caddy',
        );
        $this->runRequired(
            sprintf('sudo install -m 0600 %s %s', escapeshellarg($leaf['key']), escapeshellarg($hostKeyPath)),
            'install gateway certificate key for router-owned orbit-caddy',
        );
    }

    /**
     * Resolve a host filesystem path when the gateway API runs in the Swarm
     * container with ORBIT_HOST_PATH_PREFIX (bind mounts of /etc/caddy and
     * /etc/orbit). Bare-metal installers leave the prefix unset.
     */
    private function hostFsPath(string $absolutePath): string
    {
        if (! str_starts_with($absolutePath, '/')) {
            throw new RuntimeException("Host filesystem path must be absolute: {$absolutePath}");
        }

        $prefix = getenv('ORBIT_HOST_PATH_PREFIX');

        if (! is_string($prefix) || trim($prefix) === '') {
            return $absolutePath;
        }

        $prefix = rtrim(trim($prefix), '/');

        if (
            $prefix === ''
            || ! str_starts_with($prefix, '/')
            || str_contains($prefix, "\0")
            || array_any(
                explode('/', $prefix),
                static fn (string $segment): bool => $segment === '.'
                || $segment === '..',
            )
        ) {
            throw new RuntimeException('Gateway host path prefix is invalid.');
        }

        return $prefix.$absolutePath;
    }

    private function runRequired(string $command, string $step): void
    {
        $result = Process::timeout(60)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function runRequiredWithInput(string $command, string $input, string $step): void
    {
        $result = Process::timeout(60)->input($input)->run($command);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function runShellScript(string $script, string $step): void
    {
        $result = Process::timeout(180)->input($script)->run('bash -s');

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to {$step}: ".$this->output($result->errorOutput(), $result->output()));
    }

    private function output(string $errorOutput, string $output): string
    {
        $message = trim($errorOutput.' '.$output);

        return $message !== '' ? $message : 'unknown error';
    }

    private function bootstrapConfigRoot(string $configRoot): void
    {
        File::ensureDirectoryExists($configRoot, 0o700);
        File::ensureDirectoryExists("{$configRoot}/certs", 0o700);
        File::ensureDirectoryExists("{$configRoot}/operations-websocket", 0o700);
        File::chmod($configRoot, 0o700);
        File::chmod("{$configRoot}/certs", 0o700);
        File::chmod("{$configRoot}/operations-websocket", 0o700);
        $this->tlsKeyModeRepairer->repair($configRoot);

        $database = "{$configRoot}/gateway.sqlite";

        if (! File::exists($database)) {
            File::put($database, '');
        }

        File::chmod($database, 0o600);

        $envPath = "{$configRoot}/.env";
        $contents = File::exists($envPath) ? File::get($envPath) : '';

        foreach ([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_BUSY_TIMEOUT' => '5000',
            'DB_JOURNAL_MODE' => 'wal',
            'DB_SYNCHRONOUS' => 'NORMAL',
            'ORBIT_CONFIG_ROOT' => $configRoot,
        ] as $key => $value) {
            $contents = $this->setEnvValue($contents, $key, $value);
        }

        $operationsReverb = [
            'ORBIT_OPERATIONS_REVERB_APP_ID' =>
                $this->envValue($contents, 'ORBIT_OPERATIONS_REVERB_APP_ID') ?? 'orbit-operations',
            'ORBIT_OPERATIONS_REVERB_APP_KEY' => $this->envValue(
                $contents,
                'ORBIT_OPERATIONS_REVERB_APP_KEY',
            ) ?? Str::random(20),
            'ORBIT_OPERATIONS_REVERB_APP_SECRET' => $this->envValue(
                $contents,
                'ORBIT_OPERATIONS_REVERB_APP_SECRET',
            ) ?? Str::random(32),
            'ORBIT_OPERATIONS_REVERB_HOST' =>
                $this->envValue($contents, 'ORBIT_OPERATIONS_REVERB_HOST') ?? 'orbit-operations-reverb',
            'ORBIT_OPERATIONS_REVERB_PORT' => $this->envValue($contents, 'ORBIT_OPERATIONS_REVERB_PORT') ?? '8080',
            'ORBIT_OPERATIONS_REVERB_SCHEME' => $this->envValue($contents, 'ORBIT_OPERATIONS_REVERB_SCHEME') ?? 'http',
        ];

        foreach ($operationsReverb as $key => $value) {
            if ($this->envValue($contents, $key) !== null) {
                continue;
            }

            $contents = $this->setEnvValue($contents, $key, $value);
        }

        File::put($envPath, $contents);
        File::chmod($envPath, 0o600);

        $operationsWebSocketApps = "{$configRoot}/operations-websocket/apps.php";
        $scheme = $operationsReverb['ORBIT_OPERATIONS_REVERB_SCHEME'];

        File::put(
            $operationsWebSocketApps,
            $this->operationsWebSocketAppsFile(
                appId: $operationsReverb['ORBIT_OPERATIONS_REVERB_APP_ID'],
                appKey: $operationsReverb['ORBIT_OPERATIONS_REVERB_APP_KEY'],
                appSecret: $operationsReverb['ORBIT_OPERATIONS_REVERB_APP_SECRET'],
                host: $operationsReverb['ORBIT_OPERATIONS_REVERB_HOST'],
                port: (int) $operationsReverb['ORBIT_OPERATIONS_REVERB_PORT'],
                scheme: $scheme,
            ),
        );
        File::chmod($operationsWebSocketApps, 0o600);
    }

    /**
     * @mago-expect lint:excessive-parameter-list
     */
    private function operationsWebSocketAppsFile(
        string $appId,
        string $appKey,
        #[SensitiveParameter]
        string $appSecret,
        string $host,
        int $port,
        string $scheme,
    ): string {
        $apps = [
            [
                'key' => $appKey,
                'secret' => $appSecret,
                'app_id' => $appId,
                'options' => [
                    'host' => $host,
                    'port' => $port,
                    'scheme' => $scheme,
                    'useTLS' => $scheme === 'https',
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_message_size' => 10_000,
            ],
        ];

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export(value: $apps, return: true).";\n";
    }

    private function envValue(string $contents, string $key): ?string
    {
        $matches = [];

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function setEnvValue(string $contents, string $key, string $value): string
    {
        $line = "{$key}={$value}";

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
            return preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents) ?? $contents;
        }

        $contents = rtrim($contents);

        return $contents === '' ? "{$line}\n" : "{$contents}\n{$line}\n";
    }

    private function configRoot(?string $configRoot): string
    {
        $configRoot ??= config('orbit.paths.config_root');

        if (! is_string($configRoot) || trim($configRoot) === '') {
            throw new RuntimeException('Gateway Swarm config root is not configured.');
        }

        return rtrim($configRoot, characters: '/');
    }
}
