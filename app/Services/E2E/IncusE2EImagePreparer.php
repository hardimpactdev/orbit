<?php

declare(strict_types=1);

namespace App\Services\E2E;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\E2E\Support\IncusHost;

/**
 * Orchestrates building reusable Incus base images (orbit-blank-ubuntu-*,
 * orbit-ready-control, etc.) on a remote Incus host.
 *
 * NOTE: Only the `blank` role has a real implementation. Other roles throw
 * "not yet implemented" so the command surface is in place but actual builds
 * for control/gateway/devapp/prodapp remain follow-up work. Even the blank
 * implementation needs further refinement: SSH keys are generated remotely
 * via `$host->run('ssh-keygen ...')` but the cloud-init template reads the
 * pubkey from the local filesystem, so the current code only works when
 * artisan runs on the same host as Incus.
 */
class IncusE2EImagePreparer
{
    public function __construct(private readonly IncusHost $host) {}

    public function prepare(IncusE2EImagePreparationOptions $options): IncusE2EImagePreparationResult
    {
        $planned = array_map(
            fn (string $role): array => [
                'role' => $role,
                'alias' => $this->aliasFor($role, $options),
                'action' => 'planned',
            ],
            $options->roles,
        );

        if (! $options->force) {
            return new IncusE2EImagePreparationResult($planned);
        }

        $runId = date('YmdHis').'-'.getmypid().'-'.bin2hex(random_bytes(3));
        $instanceName = "orbit-e2e-{$runId}-prepare-blank";
        $runDirectory = sys_get_temp_dir()."/{$instanceName}";
        $privateKeyPath = "{$runDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";
        $tempInstance = null;

        if (! is_dir($runDirectory) && ! mkdir($runDirectory, 0700, recursive: true) && ! is_dir($runDirectory)) {
            throw new RuntimeException("Could not create Incus image preparation directory: {$runDirectory}");
        }

        try {
            foreach ($options->roles as $role) {
                if ($role !== 'blank') {
                    throw new RuntimeException("Role [{$role}] image build is not yet implemented.");
                }

                $this->ensureSourceImageExists($options->sourceImage);
                $this->createSshKey($privateKeyPath, $publicKeyPath, $runId);
                $publicKey = file_get_contents($publicKeyPath);

                if ($publicKey === false) {
                    throw new RuntimeException("Could not read generated public key: {$publicKeyPath}");
                }

                $this->launchBlankInstance($instanceName, $options, trim($publicKey));
                $tempInstance = $instanceName;

                $this->waitForCloudInit($instanceName, $options->timeoutSeconds);

                $ipv4 = $this->waitForIpv4($instanceName, $options->timeoutSeconds);
                $this->waitForSsh($ipv4, $privateKeyPath, $options->bootstrapUser, $options->timeoutSeconds);

                $this->cleanImageState($instanceName, $options->bootstrapUser);
                $this->stopInstance($instanceName);
                $this->publishImage($instanceName, $options->blankImageAlias);
            }

            return new IncusE2EImagePreparationResult([
                [
                    'role' => 'blank',
                    'alias' => $options->blankImageAlias,
                    'action' => 'built',
                ],
            ]);
        } finally {
            if ($tempInstance !== null) {
                $this->run('incus delete --force '.escapeshellarg($tempInstance).' >/dev/null 2>&1 || true', timeoutSeconds: 120, allowFailure: true);
            }

            File::deleteDirectory($runDirectory);
        }
    }

    private function aliasFor(string $role, IncusE2EImagePreparationOptions $options): string
    {
        return match ($role) {
            'blank' => $options->blankImageAlias,
            default => "orbit-ready-{$role}",
        };
    }

    private function ensureSourceImageExists(string $sourceImage): void
    {
        $result = $this->host->run(
            'incus image info '.escapeshellarg($sourceImage).' >/dev/null 2>&1',
            timeoutSeconds: 60,
        );

        if (! $result->successful()) {
            throw new RuntimeException("Source image [{$sourceImage}] is not available and could not be fetched.");
        }
    }

    private function createSshKey(string $privateKeyPath, string $publicKeyPath, string $runId): void
    {
        $result = $this->host->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-{$runId}"),
        ), timeoutSeconds: 60);

        if (! $result->successful()) {
            throw new RuntimeException('Failed to generate temporary SSH key.');
        }
    }

    private function launchBlankInstance(string $name, IncusE2EImagePreparationOptions $options, string $publicKey): void
    {
        $userData = $this->cloudInit($options, $publicKey);
        $userDataPath = sys_get_temp_dir()."/orbit-e2e-{$name}-user-data";
        file_put_contents($userDataPath, $userData);

        $result = $this->host->run(sprintf(
            'incus launch %s %s --vm --config=user.user-data=%s --config=limits.cpu=%s --config=limits.memory=%s >/dev/null',
            escapeshellarg($options->sourceImage),
            escapeshellarg($name),
            escapeshellarg($userDataPath),
            escapeshellarg((string) $options->cpus),
            escapeshellarg($options->memory),
        ), timeoutSeconds: $options->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to launch blank instance [{$name}].");
        }
    }

    private function waitForCloudInit(string $instanceName, int $timeoutSeconds): void
    {
        $result = $this->host->run(
            sprintf('incus exec %s -- cloud-init status --wait', escapeshellarg($instanceName)),
            timeoutSeconds: $timeoutSeconds,
        );

        if (! $result->successful()) {
            throw new RuntimeException("Cloud-init did not complete successfully on [{$instanceName}].");
        }
    }

    private function waitForIpv4(string $instanceName, int $timeoutSeconds): string
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->host->run(
                sprintf('incus list %s --format csv -c 4', escapeshellarg($instanceName)),
                timeoutSeconds: 10,
            );

            $output = $result->output();
            $ip = $this->extractIpv4($output);

            if ($ip !== null) {
                return $ip;
            }

            sleep(2);
        }

        throw new RuntimeException("Instance [{$instanceName}] did not receive an IPv4 address within {$timeoutSeconds}s.");
    }

    private function extractIpv4(string $output): ?string
    {
        if (preg_match('/(\d{1,3}\.){3}\d{1,3}/', $output, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function waitForSsh(string $ip, string $privateKeyPath, string $bootstrapUser, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->host->run(sprintf(
                'ssh -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
                escapeshellarg($privateKeyPath),
                escapeshellarg("{$bootstrapUser}@{$ip}"),
                escapeshellarg('test "$(uname -s)" = Linux && test -r /etc/os-release'),
            ), timeoutSeconds: 10);

            if ($result->successful()) {
                return;
            }

            sleep(3);
        }

        throw new RuntimeException("SSH never became ready on {$ip} as {$bootstrapUser}.");
    }

    private function cleanImageState(string $instanceName, string $bootstrapUser): void
    {
        $result = $this->host->run(sprintf(
            'incus exec %s -- sh -lc %s',
            escapeshellarg($instanceName),
            escapeshellarg(sprintf(
                'rm -f /home/%1$s/.ssh/authorized_keys && touch /home/%1$s/.ssh/authorized_keys && chown %1$s:%1$s /home/%1$s/.ssh/authorized_keys && chmod 600 /home/%1$s/.ssh/authorized_keys && grep -q "^Subsystem sftp" /etc/ssh/sshd_config || echo "Subsystem sftp /usr/lib/openssh/sftp-server" >> /etc/ssh/sshd_config && systemctl restart sshd || systemctl restart ssh || true && rm -f /etc/machine-id && touch /etc/machine-id && cloud-init clean --logs --seed || true',
                $bootstrapUser,
            )),
        ), timeoutSeconds: 60);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to clean image state on [{$instanceName}].");
        }
    }

    private function stopInstance(string $instanceName): void
    {
        $result = $this->host->run(
            sprintf('incus stop %s --timeout 120', escapeshellarg($instanceName)),
            timeoutSeconds: 180,
        );

        if (! $result->successful()) {
            throw new RuntimeException("Failed to stop instance [{$instanceName}].");
        }
    }

    private function publishImage(string $instanceName, string $alias): void
    {
        $result = $this->host->run(sprintf(
            'incus publish %s --force --reuse --alias %s >/dev/null',
            escapeshellarg($instanceName),
            escapeshellarg($alias),
        ), timeoutSeconds: 600);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to publish image [{$alias}] from [{$instanceName}].");
        }
    }

    private function run(string $command, ?int $timeoutSeconds = null, bool $allowFailure = false): ProcessResult
    {
        $result = $this->host->run($command, $timeoutSeconds);

        if (! $result->successful() && ! $allowFailure) {
            throw new RuntimeException("Command failed: {$command}\n{$result->errorOutput()}");
        }

        return $result;
    }

    private function cloudInit(IncusE2EImagePreparationOptions $options, string $publicKey): string
    {
        return <<<YAML
#cloud-config
bootcmd:
  - [ sh, -lc, "rm -f /etc/resolv.conf && printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' > /etc/resolv.conf" ]
package_update: true
packages:
  - openssh-server
  - openssh-client
ssh_pwauth: false
users:
  - default
  - name: {$options->bootstrapUser}
    groups: sudo
    shell: /bin/bash
    sudo: "ALL=(ALL) NOPASSWD:ALL"
    lock_passwd: false
    ssh_authorized_keys:
      - {$publicKey}
runcmd:
  - [ sh, -lc, "usermod -p '*' {$options->bootstrapUser}" ]
  - [ sh, -lc, "systemctl enable --now ssh || systemctl enable --now sshd || true" ]
YAML;
    }
}
