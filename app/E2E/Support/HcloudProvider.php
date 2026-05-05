<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Throwable;

final class HcloudProvider implements E2EProvider
{
    private ?E2EResourceLease $resourceLease = null;

    public function __construct(
        private E2EConfig $config,
    ) {}

    public function __destruct()
    {
        $this->releaseResourceLease();
    }

    public function name(): string
    {
        return 'hcloud';
    }

    public function config(): E2EConfig
    {
        return $this->config;
    }

    /**
     * @param  list<E2EImage>  $images
     */
    public function availability(array $images): ProviderAvailability
    {
        $this->ensureResourceLease();

        $availability = $this->inspectAvailability($images);

        if (! $availability->available) {
            $this->releaseResourceLease();
        }

        return $availability;
    }

    /**
     * @param  list<E2EImage>  $images
     */
    private function inspectAvailability(array $images): ProviderAvailability
    {
        if (! $this->run('command -v hcloud >/dev/null', timeoutSeconds: 10)->successful()) {
            return ProviderAvailability::unavailable('hcloud command is not available');
        }

        if (! $this->run('hcloud version', timeoutSeconds: 10)->successful()) {
            return ProviderAvailability::unavailable('hcloud is not authenticated or cannot reach the API');
        }

        if (! $this->run(sprintf(
            'hcloud server-type describe %s -o json >/dev/null',
            escapeshellarg($this->config->hcloudServerType),
        ), timeoutSeconds: 30)->successful()) {
            return ProviderAvailability::unavailable("hcloud server type {$this->config->hcloudServerType} is not available");
        }

        foreach ($images as $image) {
            $imageName = $this->imageReferenceFor($image);

            if ($imageName === '') {
                return ProviderAvailability::unavailable("hcloud image for {$image->value} is not configured");
            }

            if ($image === E2EImage::Blank && ! $this->run(sprintf(
                'hcloud image describe --architecture=x86 %s -o json >/dev/null',
                escapeshellarg($imageName),
            ), timeoutSeconds: 30)->successful()) {
                return ProviderAvailability::unavailable("hcloud image {$imageName} is not available");
            }
        }

        return ProviderAvailability::available('hcloud is available');
    }

    public function startRun(string $label): E2ERun
    {
        $this->ensureResourceLease();

        $safeLabel = E2ERun::safeLabel($label);
        $id = E2ERun::id();
        $directory = sys_get_temp_dir()."/{$this->config->instancePrefix}-{$id}-{$safeLabel}";

        if (! is_dir($directory) && ! mkdir($directory, 0700, recursive: true) && ! is_dir($directory)) {
            $this->releaseResourceLease();

            throw new \RuntimeException("Could not create local E2E run directory: {$directory}");
        }

        return new E2ERun($this, $id, $safeLabel, $directory);
    }

    public function createSshKeyPair(E2ERun $run): SshKeyPair
    {
        $privateKeyPath = "{$run->workDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";
        $sshKeyName = "{$this->config->instancePrefix}-{$run->id}";

        $keygen = $this->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-{$run->id}"),
        ));

        if (! $keygen->successful()) {
            throw new \RuntimeException("Could not create E2E SSH key pair: {$keygen->errorOutput()}");
        }

        $createKey = $this->run(sprintf(
            'hcloud ssh-key create --name %s --public-key-from-file %s --label orbit-e2e=true --label orbit-e2e-run=%s >/dev/null',
            escapeshellarg($sshKeyName),
            escapeshellarg($publicKeyPath),
            escapeshellarg($run->id),
        ), timeoutSeconds: 60);

        if (! $createKey->successful()) {
            throw new \RuntimeException("Could not create hcloud SSH key {$sshKeyName}: {$createKey->errorOutput()}");
        }

        $run->setMetadata('hcloud_ssh_key', $sshKeyName);

        return new SshKeyPair($privateKeyPath, $publicKeyPath, $sshKeyName);
    }

    public function launch(E2ERun $run, E2EImage $image, string $suffix): E2EInstance
    {
        $keyPair = $run->sshKeyPair() ?? $run->createSshKeyPair();
        $serverName = "{$this->config->instancePrefix}-{$run->id}-".E2ERun::safeLabel($suffix);
        $userDataPath = "{$run->workDirectory}/cloud-init-".E2ERun::safeLabel($suffix).'.yaml';

        file_put_contents($userDataPath, $this->cloudInit($keyPair));

        $createServer = $this->run(sprintf(
            'hcloud server create --name %s --type %s --image %s --location %s --ssh-key %s --label orbit-e2e=true --label orbit-e2e-run=%s --label orbit-e2e-role=%s --user-data-from-file %s -o json >/dev/null',
            escapeshellarg($serverName),
            escapeshellarg($this->config->hcloudServerType),
            escapeshellarg($this->imageReferenceFor($image)),
            escapeshellarg($this->config->hcloudLocation),
            escapeshellarg($keyPair->providerReference ?? ''),
            escapeshellarg($run->id),
            escapeshellarg($image->value),
            escapeshellarg($userDataPath),
        ), timeoutSeconds: $this->config->timeoutSeconds);

        if (! $createServer->successful()) {
            throw new \RuntimeException("Could not create hcloud server {$serverName}: {$createServer->errorOutput()}");
        }

        $instance = new HcloudInstance($this->config, $serverName, $keyPair);
        $instance->waitForIpv4();

        return $instance;
    }

    /**
     * @param  list<E2EInstance>  $instances
     */
    public function cleanup(E2ERun $run, array $instances): void
    {
        try {
            if ($this->config->keep) {
                fwrite(STDERR, "ORBIT_E2E_KEEP=1; keeping E2E run {$run->id} on hcloud\n");

                return;
            }

            foreach ($instances as $instance) {
                try {
                    $instance->delete();
                } catch (Throwable $exception) {
                    fwrite(STDERR, "Could not delete hcloud E2E server {$instance->name()}: {$exception->getMessage()}\n");
                }
            }

            $sshKeyName = $run->metadata('hcloud_ssh_key');

            if ($sshKeyName !== null) {
                $this->run(sprintf('hcloud ssh-key delete %s >/dev/null 2>&1 || true', escapeshellarg($sshKeyName)), timeoutSeconds: 60);
            }

            $this->run(sprintf('rm -rf %s', escapeshellarg($run->workDirectory)), timeoutSeconds: 30);
        } finally {
            $this->releaseResourceLease();
        }
    }

    private function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds)->run($command);
    }

    public function imageReferenceFor(E2EImage $image): string
    {
        $configured = match ($image) {
            E2EImage::Blank => $this->config->hcloudBlankImage,
            E2EImage::Base => '',
        };

        if ($configured !== '' || $image === E2EImage::Blank) {
            return $configured;
        }

        $result = $this->run(sprintf(
            'hcloud image list --selector orbit-e2e=true,orbit-e2e-image=%s --type snapshot -o json',
            escapeshellarg($image->value),
        ), timeoutSeconds: 30);

        if (! $result->successful()) {
            return '';
        }

        $images = json_decode($result->output(), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($images) || $images === []) {
            return '';
        }

        usort(
            $images,
            fn (array $first, array $second): int => strcmp((string) ($second['created'] ?? ''), (string) ($first['created'] ?? '')),
        );

        return (string) ($images[0]['id'] ?? '');
    }

    private function cloudInit(SshKeyPair $keyPair): string
    {
        $publicKey = trim((string) file_get_contents($keyPair->publicKeyPath));

        return <<<YAML
#cloud-config
users:
  - default
  - name: {$this->config->bootstrapUser}
    groups: sudo
    shell: /bin/bash
    sudo: ALL=(ALL) NOPASSWD:ALL
    ssh_authorized_keys:
      - {$publicKey}
  - name: {$this->config->controlUser}
    groups: sudo
    shell: /bin/bash
    sudo: ALL=(ALL) NOPASSWD:ALL
    ssh_authorized_keys:
      - {$publicKey}
ssh_pwauth: false
YAML;
    }

    private function ensureResourceLease(): void
    {
        if ($this->resourceLease !== null) {
            return;
        }

        if ($this->config->hcloudResourceSlots !== []) {
            $this->resourceLease = E2EResourceLeasePool::fromEnvironment(
                waitSeconds: $this->config->slotWaitSeconds,
                staleSeconds: $this->config->slotStaleSeconds,
            )->acquire('hcloud', $this->config->hcloudResourceSlots);

            $this->config = $this->config->forHcloudResource($this->resourceLease->host());

            return;
        }

        if ($this->config->hcloudLocationSlots === []) {
            return;
        }

        $this->resourceLease = E2EResourceLeasePool::fromEnvironment(
            waitSeconds: $this->config->slotWaitSeconds,
            staleSeconds: $this->config->slotStaleSeconds,
        )->acquire('hcloud', $this->config->hcloudLocationSlots);

        $this->config = $this->config->forHcloudLocation($this->resourceLease->host());
    }

    private function releaseResourceLease(): void
    {
        $this->resourceLease?->release();
        $this->resourceLease = null;
    }
}
