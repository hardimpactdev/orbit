<?php

declare(strict_types=1);

namespace App\E2E\Support;

use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class IncusHost
{
    private const string GuestBundleDirectory = '/var/tmp/orbit-e2e-bundle';

    private const string DefaultFrankenPhpImageArchive = 'frankenphp-1-php8.5-bookworm.tar';

    private const string DefaultWgEasyImageArchive = 'wg-easy-15.tar';

    private const string DefaultOrbitBinaryFilename = 'orbit-binary';

    private const string DefaultOrbitGatewayImageArchive = E2EArtifactProdManifest::GatewayImageArchive;

    private const string DefaultOrbitWebSocketImageArchive = 'orbit-reverb-current.tar';

    public function __construct(
        public readonly E2EConfig $config,
    ) {}

    public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\n{$command}"),
        );

        return Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg($this->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    public function runWithInput(string $command, string $input, ?int $timeoutSeconds = null): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\n{$command}"),
        );

        return Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds)
            ->input($input)
            ->run(sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg($this->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    public function runWithoutMultiplexing(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->runFreshSsh($command, $timeoutSeconds);
    }

    private function runFreshSsh(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\n{$command}"),
        );

        return Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds)
            ->run(sprintf(
                'ssh -S none -o ControlMaster=no -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg($this->config->host),
                escapeshellarg($remoteCommand),
            ));
    }

    /**
     * Push a local provisioning bundle to a per-run remote temp directory.
     * Returns the absolute remote path of the staged bundle. The remote path
     * always ends in `/orbit-e2e-bundle` so that `incus file push -r` keeps
     * that name when copying into the instance.
     */
    public function pushBundle(string $localBundleDir): string
    {
        if (! is_dir($localBundleDir)) {
            throw new RuntimeException("Local bundle directory not found: {$localBundleDir}");
        }

        $mktemp = $this->run('mktemp -d /tmp/orbit-e2e-stage-XXXXXX', timeoutSeconds: 30);

        if (! $mktemp->successful()) {
            throw new RuntimeException("Could not create remote bundle dir on {$this->config->host}: {$mktemp->errorOutput()}");
        }

        $stageDir = trim($mktemp->output());

        if ($stageDir === '') {
            throw new RuntimeException('Remote bundle dir mktemp returned empty.');
        }

        $bundleDir = $stageDir.'/orbit-e2e-bundle';

        $mkdir = $this->run('mkdir -p '.escapeshellarg($bundleDir), timeoutSeconds: 30);

        if (! $mkdir->successful()) {
            throw new RuntimeException("Could not create bundle subdir on {$this->config->host}: {$mkdir->errorOutput()}");
        }

        if ($this->isLocalHost($this->config->host)) {
            $copy = Process::timeout(300)->run(sprintf(
                'cp -R %s %s',
                escapeshellarg(rtrim($localBundleDir, '/').'/.'),
                escapeshellarg($bundleDir),
            ));

            if (! $copy->successful()) {
                throw new RuntimeException("Could not copy bundle locally into {$bundleDir}: {$copy->errorOutput()}");
            }

            $this->stageGatewayImageArchive($bundleDir);
            $this->stageDockerImageArchive('caddy:2-alpine', 'caddy-2-alpine.tar', $bundleDir, pullIfMissing: true);
            $this->stageDockerImageArchive('4km3/dnsmasq:latest', 'dnsmasq-latest.tar', $bundleDir, pullIfMissing: true);
            $this->stageDockerImageArchive($this->defaultFrankenPhpImage(), self::DefaultFrankenPhpImageArchive, $bundleDir, pullIfMissing: true);
            $this->stageWebSocketImageArchive($bundleDir);
            $this->stageDockerImageArchive(WgEasyServiceInstaller::Image, self::DefaultWgEasyImageArchive, $bundleDir, pullIfMissing: true);

            return $bundleDir;
        }

        $scp = Process::timeout(600)->run(sprintf(
            'scp -r -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s:%s',
            escapeshellarg(rtrim($localBundleDir, '/').'/.'),
            escapeshellarg($this->config->host),
            escapeshellarg($bundleDir),
        ));

        if (! $scp->successful()) {
            throw new RuntimeException("Could not scp bundle to {$this->config->host}:{$bundleDir}: {$scp->errorOutput()}");
        }

        $this->stageGatewayImageArchive($bundleDir);
        $this->stageDockerImageArchive('caddy:2-alpine', 'caddy-2-alpine.tar', $bundleDir, pullIfMissing: true);
        $this->stageDockerImageArchive('4km3/dnsmasq:latest', 'dnsmasq-latest.tar', $bundleDir, pullIfMissing: true);
        $this->stageDockerImageArchive($this->defaultFrankenPhpImage(), self::DefaultFrankenPhpImageArchive, $bundleDir, pullIfMissing: true);
        $this->stageWebSocketImageArchive($bundleDir);
        $this->stageDockerImageArchive(WgEasyServiceInstaller::Image, self::DefaultWgEasyImageArchive, $bundleDir, pullIfMissing: true);

        return $bundleDir;
    }

    private function defaultFrankenPhpImage(): string
    {
        return (new PhpRuntimeCatalog)->imageFor(PhpRuntimeCatalog::DEFAULT);
    }

    private function defaultGatewayImage(): string
    {
        return DockerTopologyProvider::gatewayImage();
    }

    private function defaultWebSocketImage(): string
    {
        return DockerTopologyProvider::webSocketRuntimeImage();
    }

    private function stageGatewayImageArchive(string $bundleDir): void
    {
        $this->stageDockerImageArchive(
            $this->defaultGatewayImage(),
            self::DefaultOrbitGatewayImageArchive,
            $bundleDir,
            fallbackImage: 'orbit-gateway:prepared-current',
        );
    }

    private function stageWebSocketImageArchive(string $bundleDir): void
    {
        $this->ensureWebSocketRuntimeImage();
        $this->stageDockerImageArchive($this->defaultWebSocketImage(), self::DefaultOrbitWebSocketImageArchive, $bundleDir);
    }

    private function ensureWebSocketRuntimeImage(): void
    {
        $image = escapeshellarg($this->defaultWebSocketImage());

        $result = $this->run(sprintf(
            <<<'BASH'
if ! docker image inspect %1$s >/dev/null 2>&1; then
    cat >/tmp/orbit-e2e-websocket-runtime.Dockerfile <<'DOCKERFILE'
FROM ubuntu:26.04
ENV DEBIAN_FRONTEND=noninteractive
RUN printf '%s\n' 'Acquire::ForceIPv4 "true";' 'Acquire::http::Timeout "10";' 'Acquire::https::Timeout "10";' 'Acquire::Retries "3";' >/etc/apt/apt.conf.d/99orbit-e2e-network \
    && apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends \
        ca-certificates \
        php8.5-bcmath \
        php8.5-cli \
        php8.5-common \
        php8.5-curl \
        php8.5-intl \
        php8.5-mbstring \
        php8.5-redis \
        php8.5-sqlite3 \
        php8.5-xml \
        php8.5-zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
DOCKERFILE
    docker build --pull=false -t %1$s -f /tmp/orbit-e2e-websocket-runtime.Dockerfile /tmp
fi
docker image inspect %1$s >/dev/null
BASH,
            $image,
        ), timeoutSeconds: 900);

        if (! $result->successful()) {
            throw new RuntimeException("Could not prepare {$this->defaultWebSocketImage()} on {$this->config->host}: {$result->errorOutput()}");
        }
    }

    private function stageDockerImageArchive(string $image, string $fileName, string $bundleDir, bool $pullIfMissing = false, ?string $fallbackImage = null): void
    {
        $archive = "{$bundleDir}/{$fileName}";
        $quotedImage = escapeshellarg($image);
        $fallbackCommand = '';

        if ($fallbackImage !== null && $fallbackImage !== $image) {
            $quotedFallbackImage = escapeshellarg($fallbackImage);
            $fallbackCommand = "if ! docker image inspect {$quotedImage} >/dev/null 2>&1 && docker image inspect {$quotedFallbackImage} >/dev/null 2>&1; then docker tag {$quotedFallbackImage} {$quotedImage}; fi; ";
        }

        $pullCommand = $pullIfMissing
            ? "if ! docker image inspect {$quotedImage} >/dev/null 2>&1; then docker pull {$quotedImage}; fi; "
            : '';

        $result = $this->run(sprintf(
            'if command -v docker >/dev/null 2>&1; then %s%s if docker image inspect %s >/dev/null 2>&1; then docker save %s -o %s; chmod 0644 %s; fi; fi',
            $fallbackCommand,
            $pullCommand,
            $quotedImage,
            $quotedImage,
            escapeshellarg($archive),
            escapeshellarg($archive),
        ), timeoutSeconds: 600);

        if (! $result->successful()) {
            throw new RuntimeException("Could not stage {$image} archive on {$this->config->host}: {$result->errorOutput()}");
        }
    }

    /**
     * Push a remote bundle into an Incus instance and install the requested
     * topology node kind inside it.
     */
    public function provisionInstance(
        string $instanceName,
        string $nodeKind,
        string $remoteBundleDir,
        ?string $operatorUser = null,
        ?int $timeoutSeconds = null,
    ): ProcessResult {
        if (! in_array($nodeKind, ['operator', 'gateway', 'app'], true)) {
            throw new RuntimeException("Incus topology provisioning node kind must be operator, gateway, or app; got [{$nodeKind}].");
        }

        $guestBundleDirectory = self::GuestBundleDirectory;
        $bundleTarget = "{$instanceName}/var/tmp/";

        $clearExistingBundle = $this->run(sprintf(
            'incus exec %s -- rm -rf %s',
            escapeshellarg($instanceName),
            escapeshellarg($guestBundleDirectory),
        ), timeoutSeconds: 30);

        if (! $clearExistingBundle->successful()) {
            throw new RuntimeException("Could not clear bundle target on [{$instanceName}]: {$clearExistingBundle->errorOutput()}");
        }

        $push = $this->run(sprintf(
            'incus file push -r -p %s %s',
            escapeshellarg(rtrim($remoteBundleDir, '/')),
            escapeshellarg($bundleTarget),
        ), timeoutSeconds: 600);

        if (! $push->successful()) {
            throw new RuntimeException("Could not push bundle into [{$instanceName}]: {$push->errorOutput()}");
        }

        $operatorUserArg = $operatorUser !== null
            ? ' --operator-user='.escapeshellarg($operatorUser)
            : '';

        $hasComposerCache = $this->run(
            'test -d '.escapeshellarg("{$remoteBundleDir}/composer-cache"),
            timeoutSeconds: 5,
        )->successful();

        $composerCacheArg = $hasComposerCache
            ? " --composer-cache={$guestBundleDirectory}/composer-cache"
            : '';

        $hasGatewayImageArchive = $nodeKind === 'gateway'
            && $this->run(
                'test -f '.escapeshellarg("{$remoteBundleDir}/".self::DefaultOrbitGatewayImageArchive),
                timeoutSeconds: 5,
            )->successful();

        $gatewayImageArchiveArg = $hasGatewayImageArchive
            ? " --gateway-image={$this->defaultGatewayImage()} --gateway-image-archive={$guestBundleDirectory}/".self::DefaultOrbitGatewayImageArchive
            : '';

        $hasCaddyImageArchive = $this->run(
            'test -f '.escapeshellarg("{$remoteBundleDir}/caddy-2-alpine.tar"),
            timeoutSeconds: 5,
        )->successful();

        $caddyImageArchiveArg = $hasCaddyImageArchive
            ? " --caddy-image-archive={$guestBundleDirectory}/caddy-2-alpine.tar"
            : '';

        $hasDnsmasqImageArchive = $this->run(
            'test -f '.escapeshellarg("{$remoteBundleDir}/dnsmasq-latest.tar"),
            timeoutSeconds: 5,
        )->successful();

        $dnsmasqImageArchiveArg = $hasDnsmasqImageArchive
            ? " --dnsmasq-image-archive={$guestBundleDirectory}/dnsmasq-latest.tar"
            : '';

        $hasFrankenPhpImageArchive = $this->run(
            'test -f '.escapeshellarg("{$remoteBundleDir}/".self::DefaultFrankenPhpImageArchive),
            timeoutSeconds: 5,
        )->successful();

        $frankenPhpImageArchiveArg = $hasFrankenPhpImageArchive
            ? " --frankenphp-image-archive={$guestBundleDirectory}/".self::DefaultFrankenPhpImageArchive
            : '';

        $hasWgEasyImageArchive = $nodeKind === 'gateway'
            && $this->run(
                'test -f '.escapeshellarg("{$remoteBundleDir}/".self::DefaultWgEasyImageArchive),
                timeoutSeconds: 5,
            )->successful();

        $wgEasyImageArchiveArg = $hasWgEasyImageArchive
            ? " --wg-easy-image-archive={$guestBundleDirectory}/".self::DefaultWgEasyImageArchive
            : '';

        $hasOrbitBinary = $this->run(
            'test -f '.escapeshellarg("{$remoteBundleDir}/".self::DefaultOrbitBinaryFilename),
            timeoutSeconds: 5,
        )->successful();

        $orbitBinaryArg = $hasOrbitBinary
            ? " --binary={$guestBundleDirectory}/".self::DefaultOrbitBinaryFilename
            : '';

        $sourceArchiveArg = $nodeKind === 'app' && $hasOrbitBinary
            ? ''
            : " --source-archive={$guestBundleDirectory}/orbit-source.tar.gz";

        $provisionCommand = sprintf(
            '%1$s/e2e-provision-node --node-kind=%2$s%3$s --installer=%1$s/install-orbit%4$s%5$s%6$s%7$s%8$s%9$s%10$s',
            $guestBundleDirectory,
            escapeshellarg($nodeKind),
            $sourceArchiveArg,
            $operatorUserArg,
            $composerCacheArg,
            $gatewayImageArchiveArg,
            $caddyImageArchiveArg,
            $dnsmasqImageArchiveArg,
            $frankenPhpImageArchiveArg,
            $wgEasyImageArchiveArg.$orbitBinaryArg,
        );

        $script = sprintf(
            'chmod +x %1$s/e2e-provision-node %1$s/install-orbit %1$s/_e2e-deps.sh && '
            .'%2$s',
            $guestBundleDirectory,
            $provisionCommand,
        );

        $authScript = E2EGitHubAuth::shellInputScript($script);

        $result = $authScript !== null
            ? $this->runWithInput(
                sprintf('incus exec %s -- bash -s', escapeshellarg($instanceName)),
                $authScript,
                timeoutSeconds: $timeoutSeconds ?? max(900, $this->config->timeoutSeconds),
            )
            : $this->run(
                sprintf(
                    'incus exec %s -- bash -lc %s',
                    escapeshellarg($instanceName),
                    escapeshellarg($script),
                ),
                timeoutSeconds: $timeoutSeconds ?? max(900, $this->config->timeoutSeconds),
            );

        if (! $result->successful()) {
            throw new RuntimeException("Provisioner failed on [{$instanceName}] (node_kind={$nodeKind}): {$result->errorOutput()}");
        }

        $removeProvisioningBundle = $this->run(sprintf(
            'incus exec %s -- rm -rf %s',
            escapeshellarg($instanceName),
            escapeshellarg($guestBundleDirectory),
        ), timeoutSeconds: 30);

        if (! $removeProvisioningBundle->successful()) {
            throw new RuntimeException("Could not remove bundle target on [{$instanceName}]: {$removeProvisioningBundle->errorOutput()}");
        }

        return $result;
    }

    public function cleanupBundle(string $remoteBundleDir): void
    {
        if ($remoteBundleDir === '') {
            return;
        }

        // pushBundle stages contents under <stage>/orbit-e2e-bundle; remove
        // both the bundle dir and the stage parent.
        $stageParent = dirname($remoteBundleDir);

        $this->run(
            'rm -rf '.escapeshellarg($remoteBundleDir).' '.escapeshellarg($stageParent).' || true',
            timeoutSeconds: 30,
        );
    }

    public function sourcePath(): string
    {
        return $this->validatedSourcePath((new SourceMountedCheckoutSyncer)->sourcePath($this->config->host, 'incus'));
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['', 'localhost', '127.0.0.1', '::1'], true)
            || strtolower($host) === strtolower((string) gethostname());
    }

    private function validatedSourcePath(string $path): string
    {
        $result = $this->run(sprintf(
            'test -d %s && test -f %s',
            escapeshellarg($path),
            escapeshellarg("{$path}/apps/cli/orbit"),
        ), timeoutSeconds: 30);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Configured Incus source path [{$path}] is not visible on host [{$this->config->host}] or does not contain apps/cli/orbit."
            );
        }

        return $path;
    }

    public function commandExists(string $command): bool
    {
        return $this->run(sprintf('command -v %s >/dev/null', escapeshellarg($command)))->successful();
    }

    public function imageExists(string $alias): bool
    {
        return $this->run(sprintf('incus image info %s >/dev/null 2>&1', escapeshellarg($alias)))->successful();
    }

    public function imageIdentity(string $alias): string
    {
        $result = $this->run(sprintf('incus image info %s --format json', escapeshellarg($alias)), timeoutSeconds: 30);

        if (! $result->successful()) {
            return $alias;
        }

        $payload = json_decode($result->output(), associative: true);

        if (! is_array($payload)) {
            return $alias;
        }

        $fingerprint = $payload['fingerprint'] ?? null;
        $createdAt = $payload['created_at'] ?? null;

        if (is_string($fingerprint) && $fingerprint !== '') {
            return is_string($createdAt) && $createdAt !== ''
                ? "{$alias}@{$fingerprint}:{$createdAt}"
                : "{$alias}@{$fingerprint}";
        }

        return $alias;
    }

    public function instanceExists(string $name): bool
    {
        return $this->run(sprintf('incus info %s >/dev/null 2>&1', escapeshellarg($name)))->successful();
    }

    public function runningE2EInstanceCount(): int
    {
        $result = $this->run('incus list --format json');

        if (! $result->successful()) {
            throw new RuntimeException("Could not list Incus instances on {$this->config->host}: {$result->errorOutput()}");
        }

        $instances = json_decode($result->output(), associative: true);

        if (! is_array($instances)) {
            return 0;
        }

        $prefix = $this->config->instancePrefix;
        $count = 0;

        foreach ($instances as $instance) {
            if (! is_array($instance)) {
                continue;
            }

            $name = $instance['name'] ?? null;
            $status = $instance['status'] ?? null;

            if (is_string($name) && is_string($status)
                && str_starts_with($name, $prefix)
                && $status === 'Running'
            ) {
                $count++;
            }
        }

        return $count;
    }

    public function snapshotExists(string $instance, string $snapshot): bool
    {
        return $this->run(sprintf(
            'incus query %s >/dev/null 2>&1',
            escapeshellarg("/1.0/instances/{$instance}/snapshots/{$snapshot}"),
        ))->successful();
    }

    public function readTextFile(string $path): ?string
    {
        $result = $this->runFreshSsh(sprintf(
            'test -f %1$s && cat %1$s',
            escapeshellarg($path),
        ), timeoutSeconds: 30);

        return $result->successful() ? $result->output() : null;
    }

    public function writeTextFile(string $path, string $contents): ProcessResult
    {
        return $this->runFreshSsh(sprintf(
            'mkdir -p %s && printf %%s %s | base64 -d > %s',
            escapeshellarg(dirname($path)),
            escapeshellarg(base64_encode($contents)),
            escapeshellarg($path),
        ), timeoutSeconds: 30);
    }

    public function copyInstance(string $source, string $target): ProcessResult
    {
        $storagePool = $this->storagePoolArgument();
        $storagePool = $storagePool !== '' ? " {$storagePool}" : '';

        return $this->run(sprintf(
            'incus copy %s %s%s',
            escapeshellarg($source),
            escapeshellarg($target),
            $storagePool,
        ));
    }

    public function launchInstance(string $image, string $name, string $type = '--vm', string $config = '', ?int $timeoutSeconds = null): ProcessResult
    {
        $parts = [
            'incus launch',
            escapeshellarg($image),
            escapeshellarg($name),
        ];

        if ($type !== '') {
            $parts[] = $type;
        }

        if ($config !== '') {
            $parts[] = $config;
        }

        $parts[] = $this->storagePoolArgument();
        $parts[] = '>/dev/null';

        return $this->run(implode(' ', array_filter($parts)), $timeoutSeconds);
    }

    public function launchTopologyInstance(string $image, string $name, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->launchInstance(
            image: $image,
            name: $name,
            config: sprintf(
                '--config=limits.cpu=%s --config=limits.memory=%s --device root,size=%s',
                escapeshellarg($this->config->topologyCpus),
                escapeshellarg($this->config->topologyMemory),
                escapeshellarg($this->config->topologyRootSize),
            ),
            timeoutSeconds: $timeoutSeconds,
        );
    }

    public function startInstance(string $name): ProcessResult
    {
        return $this->run(sprintf('incus start %s', escapeshellarg($name)));
    }

    public function setInstanceLimits(string $name, string $cpus, string $memory): ProcessResult
    {
        return $this->run(sprintf(
            'incus config set %s limits.cpu=%s limits.memory=%s',
            escapeshellarg($name),
            escapeshellarg($cpus),
            escapeshellarg($memory),
        ));
    }

    public function stopInstance(string $name): ProcessResult
    {
        return $this->run(sprintf(
            'incus stop %1$s --timeout 120 || incus stop %1$s --force',
            escapeshellarg($name),
        ), timeoutSeconds: 180);
    }

    public function forceStopInstance(string $name): ProcessResult
    {
        return $this->run(sprintf(
            'incus stop %s --force',
            escapeshellarg($name),
        ), timeoutSeconds: 30);
    }

    /**
     * @param  list<string>  $names
     */
    public function stopInstancesIfRunning(array $names): ProcessResult
    {
        $lines = [];
        $waitLines = [];

        foreach (array_values($names) as $index => $name) {
            $pid = "PID_STOP_{$index}";
            $lines[] = sprintf(
                'incus stop %s --force >/dev/null 2>&1 || true & %s=$!',
                escapeshellarg($name),
                $pid,
            );
            $waitLines[] = "wait \${$pid} || true";
        }

        return $this->run(implode("\n", [
            ...$lines,
            ...$waitLines,
        ]), timeoutSeconds: 180);
    }

    /**
     * @param  list<string>  $names
     */
    public function startInstancesIfStopped(array $names): ProcessResult
    {
        $lines = [];
        $waitLines = [];

        foreach (array_values($names) as $index => $name) {
            $pid = "PID_START_IF_NEEDED_{$index}";
            $quotedName = escapeshellarg($name);

            $lines[] = "incus start {$quotedName} >/dev/null 2>&1 || incus list --format csv -c n,s | awk -F, -v name={$quotedName} '\$1 == name {print \$2}' | grep -qx RUNNING & {$pid}=\$!";
            $waitLines[] = "wait \${$pid}";
        }

        return $this->run(implode("\n", [
            ...$lines,
            ...$waitLines,
        ]), timeoutSeconds: 180);
    }

    /**
     * @param  list<string>  $names
     */
    public function deleteInstancesIfPresent(array $names): ProcessResult
    {
        $lines = array_map(
            static fn (string $name): string => sprintf(
                'incus delete --force %s >/dev/null 2>&1 || true',
                escapeshellarg($name),
            ),
            $names,
        );

        return $this->run(implode("\n", $lines), timeoutSeconds: 180);
    }

    public function deleteInstance(string $name): ProcessResult
    {
        return $this->run(sprintf('incus delete %s --force', escapeshellarg($name)));
    }

    public function snapshotInstance(string $name, string $snapshot): ProcessResult
    {
        return $this->run(sprintf(
            'incus snapshot create %s %s',
            escapeshellarg($name),
            escapeshellarg($snapshot),
        ));
    }

    /**
     * @param  list<string>  $names
     */
    public function snapshotInstancesConcurrently(array $names, string $snapshot): ProcessResult
    {
        $lines = [];
        $waitLines = [];

        foreach (array_values($names) as $index => $name) {
            $pid = "PID_SNAPSHOT_{$index}";
            $lines[] = sprintf(
                'incus snapshot create %s %s & %s=$!',
                escapeshellarg($name),
                escapeshellarg($snapshot),
                $pid,
            );
            $waitLines[] = "wait \${$pid}";
        }

        return $this->run(implode("\n", [
            ...$lines,
            ...$waitLines,
        ]), timeoutSeconds: 300);
    }

    public function snapshotStatefulInstance(string $name, string $snapshot): ProcessResult
    {
        return $this->run(sprintf(
            'incus snapshot create %s %s --stateful --reuse',
            escapeshellarg($name),
            escapeshellarg($snapshot),
        ));
    }

    public function restoreSnapshot(string $name, string $snapshot, bool $stateful = false): ProcessResult
    {
        return $this->run(sprintf(
            'incus snapshot restore %s %s%s',
            escapeshellarg($name),
            escapeshellarg($snapshot),
            $stateful ? ' --stateful' : '',
        ));
    }

    /**
     * @param  list<string>  $names
     */
    public function restoreSnapshotsConcurrently(array $names, string $snapshot, bool $stateful = false): ProcessResult
    {
        $lines = [];
        $waitLines = [];

        foreach (array_values($names) as $index => $name) {
            $pid = "PID_RESTORE_{$index}";
            $lines[] = sprintf(
                'incus snapshot restore %s %s%s & %s=$!',
                escapeshellarg($name),
                escapeshellarg($snapshot),
                $stateful ? ' --stateful' : '',
                $pid,
            );
            $waitLines[] = "wait \${$pid}";
        }

        return $this->run(implode("\n", [
            ...$lines,
            ...$waitLines,
        ]));
    }

    public function setInstanceConfig(string $name, string $key, string $value): ProcessResult
    {
        return $this->run(sprintf(
            'incus config set %s %s=%s',
            escapeshellarg($name),
            escapeshellarg($key),
            escapeshellarg($value),
        ));
    }

    public function deleteSnapshot(string $name, string $snapshot): ProcessResult
    {
        return $this->run(sprintf(
            'incus snapshot delete %s %s >/dev/null 2>&1 || true',
            escapeshellarg($name),
            escapeshellarg($snapshot),
        ));
    }

    /**
     * @param  list<string>  $names
     */
    public function deleteSnapshotsIfPresent(array $names, string $snapshot): ProcessResult
    {
        $lines = [];
        $waitLines = [];

        foreach (array_values($names) as $index => $name) {
            $pid = "PID_DELETE_SNAPSHOT_{$index}";
            $lines[] = sprintf(
                'incus snapshot delete %s %s >/dev/null 2>&1 || true & %s=$!',
                escapeshellarg($name),
                escapeshellarg($snapshot),
                $pid,
            );
            $waitLines[] = "wait \${$pid} || true";
        }

        return $this->run(implode("\n", [
            ...$lines,
            ...$waitLines,
        ]), timeoutSeconds: 180);
    }

    public function storagePoolArgument(): string
    {
        if ($this->config->incusStoragePool === '') {
            return '';
        }

        return '--storage '.escapeshellarg($this->config->incusStoragePool);
    }
}
