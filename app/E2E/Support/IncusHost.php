<?php

declare(strict_types=1);

namespace App\E2E\Support;

use App\Services\Php\PhpRuntimeCatalog;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class IncusHost
{
    private const string GuestBundleDirectory = '/var/tmp/orbit-e2e-bundle';

    private const string DefaultFrankenPhpImageArchive = 'frankenphp-1-php8.5-bookworm.tar';

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

            $this->stageDockerImageArchive('orbit-runtime:current', 'orbit-runtime-current.tar', $bundleDir);
            $this->stageDockerImageArchive('caddy:2-alpine', 'caddy-2-alpine.tar', $bundleDir);
            $this->stageDockerImageArchive('4km3/dnsmasq:latest', 'dnsmasq-latest.tar', $bundleDir);
            $this->stageDockerImageArchive($this->defaultFrankenPhpImage(), self::DefaultFrankenPhpImageArchive, $bundleDir);

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

        $this->stageDockerImageArchive('orbit-runtime:current', 'orbit-runtime-current.tar', $bundleDir);
        $this->stageDockerImageArchive('caddy:2-alpine', 'caddy-2-alpine.tar', $bundleDir);
        $this->stageDockerImageArchive('4km3/dnsmasq:latest', 'dnsmasq-latest.tar', $bundleDir);
        $this->stageDockerImageArchive($this->defaultFrankenPhpImage(), self::DefaultFrankenPhpImageArchive, $bundleDir);

        return $bundleDir;
    }

    private function defaultFrankenPhpImage(): string
    {
        return (new PhpRuntimeCatalog)->imageFor(PhpRuntimeCatalog::DEFAULT);
    }

    private function stageDockerImageArchive(string $image, string $fileName, string $bundleDir): void
    {
        $archive = "{$bundleDir}/{$fileName}";

        $result = $this->run(sprintf(
            'if command -v docker >/dev/null 2>&1 && docker image inspect %s >/dev/null 2>&1; then docker save %s -o %s; chmod 0644 %s; fi',
            escapeshellarg($image),
            escapeshellarg($image),
            escapeshellarg($archive),
            escapeshellarg($archive),
        ), timeoutSeconds: 600);

        if (! $result->successful()) {
            throw new RuntimeException("Could not stage {$image} archive on {$this->config->host}: {$result->errorOutput()}");
        }
    }

    /**
     * Push a remote bundle into an Incus instance and install the requested
     * topology role inside it.
     */
    public function provisionInstance(
        string $instanceName,
        string $role,
        string $remoteBundleDir,
        ?string $operatorUser = null,
        ?int $timeoutSeconds = null,
    ): ProcessResult {
        if (! in_array($role, ['operator', 'control', 'gateway', 'app'], true)) {
            throw new RuntimeException("Incus topology provisioning role must be operator, gateway, or app; got [{$role}].");
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

        $hasRuntimeImageArchive = $this->run(
            'test -f '.escapeshellarg("{$remoteBundleDir}/orbit-runtime-current.tar"),
            timeoutSeconds: 5,
        )->successful();

        $runtimeImageArchiveArg = $hasRuntimeImageArchive
            ? " --runtime-image-archive={$guestBundleDirectory}/orbit-runtime-current.tar"
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

        $script = sprintf(
            'chmod +x %1$s/e2e-provision-node %1$s/install-orbit %1$s/_e2e-deps.sh && '
            .'%1$s/e2e-provision-node --role=%2$s --source-archive=%1$s/orbit-source.tar.gz --installer=%1$s/install-orbit%3$s%4$s%5$s%6$s%7$s%8$s',
            $guestBundleDirectory,
            escapeshellarg($role),
            $operatorUserArg,
            $composerCacheArg,
            $runtimeImageArchiveArg,
            $caddyImageArchiveArg,
            $dnsmasqImageArchiveArg,
            $frankenPhpImageArchiveArg,
        );

        $command = sprintf(
            'incus exec %s -- bash -lc %s',
            escapeshellarg($instanceName),
            escapeshellarg($script),
        );

        $result = $this->run($command, timeoutSeconds: $timeoutSeconds ?? max(900, $this->config->timeoutSeconds));

        if (! $result->successful()) {
            throw new RuntimeException("Provisioner failed on [{$instanceName}] (role={$role}): {$result->errorOutput()}");
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

    /**
     * Wait until cloud-init has fully completed inside an Incus instance,
     * including any queued reboots (Ubuntu cloud images sometimes queue a
     * netplan-ovs-cleanup → systemd-reboot transaction during first boot).
     * Tolerates the "degraded done" exit status (cloud-init exit code 2).
     */
    public function waitForCloudInit(string $instanceName, ?int $timeoutSeconds = null): void
    {
        $timeout = $timeoutSeconds ?? $this->config->timeoutSeconds;
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $remainingSeconds = max(1, $deadline - time());

            $result = $this->run(
                sprintf('incus exec %s -- cloud-init status', escapeshellarg($instanceName)),
                timeoutSeconds: min(10, $remainingSeconds),
            );

            $exitCode = $result->exitCode();

            $output = trim($result->output().$result->errorOutput());

            if (str_contains($output, 'status: done') || str_contains($output, 'status: degraded done')) {
                return;
            }

            // exitCode 255 typically means the agent went away (reboot in
            // progress). Sleep briefly and retry.
            sleep(3);
        }

        throw new RuntimeException("Cloud-init did not finish on [{$instanceName}] within {$timeout}s.");
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

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['', 'localhost', '127.0.0.1', '::1'], true)
            || strtolower($host) === strtolower((string) gethostname());
    }

    public function commandExists(string $command): bool
    {
        return $this->run(sprintf('command -v %s >/dev/null', escapeshellarg($command)))->successful();
    }

    public function imageExists(string $alias): bool
    {
        return $this->run(sprintf('incus image info %s >/dev/null 2>&1', escapeshellarg($alias)))->successful();
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

    /**
     * @param  list<string>  $names
     */
    public function stopInstancesIfRunning(array $names): ProcessResult
    {
        $lines = array_map(
            static fn (string $name): string => sprintf(
                'incus stop %s --force >/dev/null 2>&1 || true',
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

    public function storagePoolArgument(): string
    {
        if ($this->config->incusStoragePool === '') {
            return '';
        }

        return '--storage '.escapeshellarg($this->config->incusStoragePool);
    }
}
