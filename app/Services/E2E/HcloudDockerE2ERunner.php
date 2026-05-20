<?php

declare(strict_types=1);

namespace App\Services\E2E;

use App\E2E\Support\E2EResourceLease;
use App\E2E\Support\E2EResourceLeasePool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class HcloudDockerE2ERunner
{
    public function __construct(
        private int $retrySleepSeconds = 5,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     dry_run: bool,
     *     server: string,
     *     docker_host: string,
     *     prepared: bool,
     *     tested: bool
     * }
     */
    public function run(HcloudDockerE2ERunOptions $options): array
    {
        if (! $options->force) {
            return [
                'provider' => 'hcloud',
                'dry_run' => true,
                'server' => '',
                'docker_host' => '',
                'prepared' => false,
                'tested' => false,
            ];
        }

        $runId = date('YmdHis').'-'.getmypid().'-'.bin2hex(random_bytes(3));
        $runDirectory = sys_get_temp_dir()."/{$options->prefix}-{$runId}-hcloud-docker";
        $sshKeyName = "{$options->prefix}-{$runId}-docker-host";
        $serverName = "{$options->prefix}-{$runId}-docker-host";
        $privateKeyPath = "{$runDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";
        $resourceLease = $this->acquireResourceLease($options);
        $effectiveOptions = $resourceLease !== null
            ? $this->optionsForResource($options, $resourceLease->host())
            : $options;

        if (! is_dir($runDirectory) && ! mkdir($runDirectory, 0700, recursive: true) && ! is_dir($runDirectory)) {
            $resourceLease?->release();

            throw new RuntimeException("Could not create hcloud Docker E2E directory: {$runDirectory}");
        }

        try {
            $this->createSshKey($privateKeyPath, $publicKeyPath, $sshKeyName, $runId);
            $this->createServer($serverName, $sshKeyName, $runId, $runDirectory, $effectiveOptions);

            $ip = $this->waitForIpv4($serverName, $effectiveOptions->timeoutSeconds);
            $dockerHost = "root@{$ip}";

            $this->waitForDocker($dockerHost, $privateKeyPath, $effectiveOptions->timeoutSeconds);
            $this->prepareDockerImages($dockerHost, $effectiveOptions);
            $this->runDockerE2E($dockerHost, $effectiveOptions);

            return [
                'provider' => 'hcloud',
                'dry_run' => false,
                'server' => $serverName,
                'docker_host' => $dockerHost,
                'prepared' => true,
                'tested' => true,
            ];
        } finally {
            if (! $options->keep) {
                $this->runCommand('hcloud server delete '.escapeshellarg($serverName).' >/dev/null 2>&1 || true', timeoutSeconds: 120, allowFailure: true);
                $this->runCommand('hcloud ssh-key delete '.escapeshellarg($sshKeyName).' >/dev/null 2>&1 || true', timeoutSeconds: 60, allowFailure: true);
                File::deleteDirectory($runDirectory);
            }

            $resourceLease?->release();
        }
    }

    private function acquireResourceLease(HcloudDockerE2ERunOptions $options): ?E2EResourceLease
    {
        if ($options->resourceSlots === []) {
            return null;
        }

        return E2EResourceLeasePool::fromEnvironment(
            waitSeconds: $options->slotWaitSeconds,
            staleSeconds: $options->slotStaleSeconds,
        )->acquire('hcloud-docker', $options->resourceSlots);
    }

    private function optionsForResource(HcloudDockerE2ERunOptions $options, string $resource): HcloudDockerE2ERunOptions
    {
        [$location, $serverType, $sourceImage] = array_pad(explode('/', $resource, 3), 3, '');

        if ($location === '' || $serverType === '' || $sourceImage === '') {
            throw new RuntimeException("Invalid hcloud Docker resource slot [{$resource}]. Expected location/server-type/image.");
        }

        return new HcloudDockerE2ERunOptions(
            force: $options->force,
            keep: $options->keep,
            sourceImage: $sourceImage,
            serverType: $serverType,
            location: $location,
            resourceSlots: $options->resourceSlots,
            slotWaitSeconds: $options->slotWaitSeconds,
            slotStaleSeconds: $options->slotStaleSeconds,
            prefix: $options->prefix,
            kind: $options->kind,
            processes: $options->processes,
            timeoutSeconds: $options->timeoutSeconds,
        );
    }

    private function createSshKey(string $privateKeyPath, string $publicKeyPath, string $sshKeyName, string $runId): void
    {
        $this->runCommand(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-{$runId}"),
        ), timeoutSeconds: 60);

        $this->runCommand(sprintf(
            'hcloud ssh-key create --name %s --public-key-from-file %s --label orbit-e2e=true --label orbit-e2e-run=%s --label orbit-e2e-purpose=docker-host >/dev/null',
            escapeshellarg($sshKeyName),
            escapeshellarg($publicKeyPath),
            escapeshellarg($runId),
        ), timeoutSeconds: 60);
    }

    private function createServer(string $serverName, string $sshKeyName, string $runId, string $runDirectory, HcloudDockerE2ERunOptions $options): void
    {
        $userData = "{$runDirectory}/{$serverName}-cloud-init.yaml";
        file_put_contents($userData, $this->cloudInit());

        $this->runCommand(sprintf(
            'hcloud server create --name %s --type %s --image %s --location %s --ssh-key %s --label orbit-e2e=true --label orbit-e2e-run=%s --label orbit-e2e-purpose=docker-host --user-data-from-file %s -o json >/dev/null',
            escapeshellarg($serverName),
            escapeshellarg($options->serverType),
            escapeshellarg($options->sourceImage),
            escapeshellarg($options->location),
            escapeshellarg($sshKeyName),
            escapeshellarg($runId),
            escapeshellarg($userData),
        ), timeoutSeconds: $options->timeoutSeconds);
    }

    private function waitForIpv4(string $serverName, int $timeoutSeconds): string
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->runCommand(sprintf('hcloud server ip %s', escapeshellarg($serverName)), timeoutSeconds: 10, allowFailure: true);
            $ip = trim($result);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }

            $this->sleepBetweenAttempts(min(2, $this->retrySleepSeconds));
        }

        throw new RuntimeException("hcloud server {$serverName} did not receive an IPv4 address.");
    }

    private function waitForDocker(string $dockerHost, string $privateKeyPath, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = Process::timeout(15)->run(sprintf(
                'ssh -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
                escapeshellarg($privateKeyPath),
                escapeshellarg($dockerHost),
                escapeshellarg('docker info >/dev/null'),
            ));

            if ($result->successful()) {
                return;
            }

            $this->sleepBetweenAttempts($this->retrySleepSeconds);
        }

        throw new RuntimeException("Docker did not become ready on {$dockerHost}.");
    }

    private function prepareDockerImages(string $dockerHost, HcloudDockerE2ERunOptions $options): void
    {
        $this->runCommand(
            sprintf('composer e2e:prepare-docker-hosts -- --force %s', escapeshellarg($options->kind)),
            timeoutSeconds: $options->timeoutSeconds,
            environment: ['ORBIT_E2E_DOCKER_HOSTS' => $dockerHost],
        );
    }

    private function runDockerE2E(string $dockerHost, HcloudDockerE2ERunOptions $options): void
    {
        $this->runCommand(
            'composer test:e2e:docker',
            timeoutSeconds: $options->timeoutSeconds,
            environment: [
                'ORBIT_E2E_DOCKER_HOSTS' => $dockerHost,
                'ORBIT_E2E_PARALLEL_PROCESSES' => (string) $options->processes,
            ],
        );
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function runCommand(string $command, ?int $timeoutSeconds = null, bool $allowFailure = false, array $environment = []): string
    {
        $process = Process::timeout($timeoutSeconds ?? 600);

        if ($environment !== []) {
            $process = $process->env($environment);
        }

        $result = $process->run($command);

        if (! $result->successful() && ! $allowFailure) {
            throw new RuntimeException("Command failed: {$command}\n{$result->errorOutput()}");
        }

        return $result->output();
    }

    private function sleepBetweenAttempts(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    private function cloudInit(): string
    {
        return <<<'YAML'
#cloud-config
package_update: true
packages:
  - docker.io
runcmd:
  - systemctl enable --now docker
YAML;
    }
}
