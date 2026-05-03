<?php

declare(strict_types=1);

namespace App\Services\E2E;

use RuntimeException;
use Tests\E2E\Support\IncusHost;

/**
 * Orchestrates building reusable Incus base images on a remote Incus host.
 *
 * All filesystem operations execute on the Incus host via `$host->run(...)`,
 * so the artisan process does not need to share a filesystem with Incus.
 *
 * Supported roles:
 *   - blank   → orbit-blank-ubuntu-* (cloud-init bootstraps a sudoers user)
 *
 * Roles that still throw "not yet implemented":
 *   - control, gateway, devapp, prodapp
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

        $built = [];

        foreach ($options->roles as $role) {
            $built[] = match ($role) {
                'blank' => $this->buildBlank($options),
                default => throw new RuntimeException("Role [{$role}] image build is not yet implemented."),
            };
        }

        return new IncusE2EImagePreparationResult($built);
    }

    /**
     * @return array{role: string, alias: string, action: string}
     */
    private function buildBlank(IncusE2EImagePreparationOptions $options): array
    {
        $runId = date('YmdHis').'-'.getmypid().'-'.bin2hex(random_bytes(3));
        $instanceName = "orbit-e2e-{$runId}-prepare-blank";
        $remoteWorkDir = $this->createRemoteWorkDir($instanceName);
        $tempInstance = null;

        try {
            $this->ensureSourceImageExists($options->sourceImage);

            $remotePrivateKey = "{$remoteWorkDir}/id_ed25519";
            $publicKey = $this->createRemoteSshKey($remotePrivateKey, $runId);

            $this->launchBlankInstance($instanceName, $remoteWorkDir, $options, $publicKey);
            $tempInstance = $instanceName;

            $this->waitForCloudInit($instanceName, $options->timeoutSeconds);

            $ipv4 = $this->waitForIpv4($instanceName, $options->timeoutSeconds);
            $this->waitForSsh($ipv4, $remotePrivateKey, $options->bootstrapUser, $options->timeoutSeconds);

            $this->cleanImageState($instanceName, $options->bootstrapUser);
            $this->stopInstance($instanceName);
            $this->publishImage($instanceName, $options->blankImageAlias);

            return [
                'role' => 'blank',
                'alias' => $options->blankImageAlias,
                'action' => 'built',
            ];
        } finally {
            if ($tempInstance !== null) {
                $this->host->run(
                    'incus delete --force '.escapeshellarg($tempInstance).' >/dev/null 2>&1 || true',
                    timeoutSeconds: 120,
                );
            }

            $this->host->run(
                'rm -rf '.escapeshellarg($remoteWorkDir).' || true',
                timeoutSeconds: 30,
            );
        }
    }

    private function aliasFor(string $role, IncusE2EImagePreparationOptions $options): string
    {
        return match ($role) {
            'blank' => $options->blankImageAlias,
            default => "orbit-ready-{$role}",
        };
    }

    private function createRemoteWorkDir(string $instanceName): string
    {
        $template = '/tmp/'.$instanceName.'-XXXXXX';

        $result = $this->host->run('mktemp -d '.escapeshellarg($template), timeoutSeconds: 30);

        if (! $result->successful()) {
            throw new RuntimeException('Could not create remote work directory.');
        }

        $path = trim($result->output());

        if ($path === '') {
            throw new RuntimeException('mktemp returned an empty path.');
        }

        return $path;
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

    private function createRemoteSshKey(string $privateKeyPath, string $runId): string
    {
        $generate = $this->host->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-{$runId}"),
        ), timeoutSeconds: 60);

        if (! $generate->successful()) {
            throw new RuntimeException('Failed to generate temporary SSH key on Incus host.');
        }

        $read = $this->host->run('cat '.escapeshellarg($privateKeyPath.'.pub'), timeoutSeconds: 10);

        if (! $read->successful()) {
            throw new RuntimeException('Failed to read generated public key on Incus host.');
        }

        $publicKey = trim($read->output());

        if ($publicKey === '') {
            throw new RuntimeException('Generated public key is empty.');
        }

        return $publicKey;
    }

    private function launchBlankInstance(
        string $name,
        string $remoteWorkDir,
        IncusE2EImagePreparationOptions $options,
        string $publicKey,
    ): void {
        $userData = $this->cloudInit($options, $publicKey);
        $userDataPath = "{$remoteWorkDir}/user-data.yaml";

        $write = $this->host->run(sprintf(
            "cat > %s <<'CLOUDINITEOF'\n%s\nCLOUDINITEOF\n",
            escapeshellarg($userDataPath),
            $userData,
        ), timeoutSeconds: 30);

        if (! $write->successful()) {
            throw new RuntimeException("Failed to write cloud-init user-data on Incus host: {$write->errorOutput()}");
        }

        $launch = $this->host->run(sprintf(
            'incus launch %s %s --vm --config=user.user-data="$(cat %s)" --config=limits.cpu=%s --config=limits.memory=%s >/dev/null',
            escapeshellarg($options->sourceImage),
            escapeshellarg($name),
            escapeshellarg($userDataPath),
            escapeshellarg((string) $options->cpus),
            escapeshellarg($options->memory),
        ), timeoutSeconds: $options->timeoutSeconds);

        if (! $launch->successful()) {
            throw new RuntimeException("Failed to launch blank instance [{$name}]: {$launch->errorOutput()}");
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

            $ip = $this->extractIpv4($result->output());

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

    private function waitForSsh(string $ip, string $remotePrivateKey, string $bootstrapUser, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->host->run(sprintf(
                'ssh -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
                escapeshellarg($remotePrivateKey),
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
        $script = sprintf(
            'rm -f /home/%1$s/.ssh/authorized_keys && '
            .'touch /home/%1$s/.ssh/authorized_keys && '
            .'chown %1$s:%1$s /home/%1$s/.ssh/authorized_keys && '
            .'chmod 600 /home/%1$s/.ssh/authorized_keys && '
            .'grep -q "^Subsystem sftp" /etc/ssh/sshd_config || echo "Subsystem sftp /usr/lib/openssh/sftp-server" >> /etc/ssh/sshd_config && '
            .'systemctl restart sshd || systemctl restart ssh || true && '
            .'rm -f /etc/machine-id && '
            .'touch /etc/machine-id && '
            .'cloud-init clean --logs --seed || true',
            $bootstrapUser,
        );

        $result = $this->host->run(sprintf(
            'incus exec %s -- sh -lc %s',
            escapeshellarg($instanceName),
            escapeshellarg($script),
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
