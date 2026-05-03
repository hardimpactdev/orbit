<?php

declare(strict_types=1);

namespace App\Services\E2E;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class HcloudE2EImagePreparer
{
    /**
     * @return array{
     *     provider: string,
     *     dry_run: bool,
     *     roles: list<array{role: string, description: string, server: string, snapshot_created: bool}>
     * }
     */
    public function prepare(HcloudE2EImagePreparationOptions $options): array
    {
        $planned = array_map(
            fn (string $role): array => [
                'role' => $role,
                'description' => $this->descriptionFor($role, $options),
                'server' => '',
                'snapshot_created' => false,
            ],
            $options->roles,
        );

        if (! $options->force) {
            return [
                'provider' => 'hcloud',
                'dry_run' => true,
                'roles' => $planned,
            ];
        }

        $runId = date('YmdHis').'-'.getmypid().'-'.bin2hex(random_bytes(3));
        $runDirectory = sys_get_temp_dir()."/{$options->prefix}-{$runId}-hcloud-image-prepare";
        $sshKeyName = "{$options->prefix}-{$runId}-image-prepare";
        $privateKeyPath = "{$runDirectory}/id_ed25519";
        $publicKeyPath = "{$privateKeyPath}.pub";
        $sourceArchivePath = "{$runDirectory}/orbit-source.tar.gz";
        $installerPath = base_path('bin/install-orbit');

        if (! is_dir($runDirectory) && ! mkdir($runDirectory, 0700, recursive: true) && ! is_dir($runDirectory)) {
            throw new RuntimeException("Could not create hcloud image preparation directory: {$runDirectory}");
        }

        $servers = [];

        try {
            $this->buildSourceArchive($sourceArchivePath);
            $this->createSshKey($privateKeyPath, $publicKeyPath, $sshKeyName, $runId);

            $prepared = [];

            foreach ($options->roles as $role) {
                $serverName = "{$options->prefix}-{$runId}-prepare-{$role}";
                $servers[] = $serverName;

                $this->createServer($serverName, $role, $sshKeyName, $runId, $runDirectory, $options);

                $ip = $this->waitForIpv4($serverName, $options->timeoutSeconds);

                $this->waitForSsh($ip, $privateKeyPath, $options->timeoutSeconds);
                $this->copyInstallerBundle($ip, $privateKeyPath, $sourceArchivePath, $installerPath);
                $this->prepareRole($ip, $privateKeyPath, $role, $options);
                $this->snapshot($serverName, $role, $runId, $options);

                $prepared[] = [
                    'role' => $role,
                    'description' => $this->descriptionFor($role, $options),
                    'server' => $serverName,
                    'snapshot_created' => true,
                ];
            }

            return [
                'provider' => 'hcloud',
                'dry_run' => false,
                'roles' => $prepared,
            ];
        } finally {
            if (! $options->keep) {
                foreach (array_reverse($servers) as $server) {
                    $this->run('hcloud server delete '.escapeshellarg($server).' >/dev/null 2>&1 || true', timeoutSeconds: 120, allowFailure: true);
                }

                $this->run('hcloud ssh-key delete '.escapeshellarg($sshKeyName).' >/dev/null 2>&1 || true', timeoutSeconds: 60, allowFailure: true);
                File::deleteDirectory($runDirectory);
            }
        }
    }

    private function buildSourceArchive(string $target): void
    {
        $command = sprintf(
            "COPYFILE_DISABLE=1 tar --exclude='./.git' --exclude='./.env' --exclude='./database/*.sqlite' --exclude='./database/*.sqlite-*' --exclude='./node_modules' --exclude='./storage/framework/cache/data/*' --exclude='./storage/framework/sessions/*' --exclude='./storage/framework/testing/*' --exclude='./storage/framework/views/*' --exclude='./storage/logs/*' --exclude='./vendor' -czf %s -C %s .",
            escapeshellarg($target),
            escapeshellarg(base_path()),
        );

        $this->run($command, timeoutSeconds: 120);
    }

    private function createSshKey(string $privateKeyPath, string $publicKeyPath, string $sshKeyName, string $runId): void
    {
        $this->run(sprintf(
            'ssh-keygen -t ed25519 -N %s -f %s -C %s >/dev/null',
            escapeshellarg(''),
            escapeshellarg($privateKeyPath),
            escapeshellarg("orbit-e2e-{$runId}"),
        ), timeoutSeconds: 60);

        $this->run(sprintf(
            'hcloud ssh-key create --name %s --public-key-from-file %s --label orbit-e2e=true --label orbit-e2e-run=%s --label orbit-e2e-purpose=image-prepare >/dev/null',
            escapeshellarg($sshKeyName),
            escapeshellarg($publicKeyPath),
            escapeshellarg($runId),
        ), timeoutSeconds: 60);
    }

    private function createServer(string $serverName, string $role, string $sshKeyName, string $runId, string $runDirectory, HcloudE2EImagePreparationOptions $options): void
    {
        $userData = "{$runDirectory}/{$serverName}-cloud-init.yaml";
        file_put_contents($userData, $this->cloudInit($options));

        $this->run(sprintf(
            'hcloud server create --name %s --type %s --image %s --location %s --ssh-key %s --label orbit-e2e=true --label orbit-e2e-run=%s --label orbit-e2e-purpose=image-prepare --label orbit-e2e-image=%s --user-data-from-file %s -o json >/dev/null',
            escapeshellarg($serverName),
            escapeshellarg($options->serverType),
            escapeshellarg($options->sourceImage),
            escapeshellarg($options->location),
            escapeshellarg($sshKeyName),
            escapeshellarg($runId),
            escapeshellarg($role),
            escapeshellarg($userData),
        ), timeoutSeconds: $options->timeoutSeconds);
    }

    private function waitForIpv4(string $serverName, int $timeoutSeconds): string
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->run(sprintf('hcloud server ip %s', escapeshellarg($serverName)), timeoutSeconds: 10, allowFailure: true);
            $ip = trim($result);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }

            sleep(2);
        }

        throw new RuntimeException("hcloud server {$serverName} did not receive an IPv4 address.");
    }

    private function waitForSsh(string $ip, string $privateKeyPath, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            try {
                $this->ssh($ip, $privateKeyPath, 'test "$(uname -s)" = Linux && test -r /etc/os-release', timeoutSeconds: 10);

                return;
            } catch (RuntimeException) {
                sleep(3);
            }
        }

        throw new RuntimeException("SSH never became ready on {$ip}.");
    }

    private function copyInstallerBundle(string $ip, string $privateKeyPath, string $sourceArchivePath, string $installerPath): void
    {
        $this->run(sprintf(
            'scp -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s %s',
            escapeshellarg($privateKeyPath),
            escapeshellarg($sourceArchivePath),
            escapeshellarg($installerPath),
            escapeshellarg("root@{$ip}:/tmp/"),
        ));
    }

    private function prepareRole(string $ip, string $privateKeyPath, string $role, HcloudE2EImagePreparationOptions $options): void
    {
        match ($role) {
            'control' => $this->prepareControl($ip, $privateKeyPath, $options),
            'gateway' => $this->prepareGateway($ip, $privateKeyPath, $options),
            'devapp' => $this->prepareDevapp($ip, $privateKeyPath, $options),
            default => throw new RuntimeException("Unknown hcloud image role [{$role}]."),
        };

        $this->cleanImageState($ip, $privateKeyPath, $options);
    }

    private function prepareControl(string $ip, string $privateKeyPath, HcloudE2EImagePreparationOptions $options): void
    {
        $user = $options->controlUser;

        $this->ssh($ip, $privateKeyPath, $this->ensureSudoUserCommand($user));
        $this->ssh($ip, $privateKeyPath, "chmod +x /tmp/install-orbit && chown {$user}:{$user} /tmp/orbit-source.tar.gz /tmp/install-orbit");
        $this->ssh($ip, $privateKeyPath, "/tmp/install-orbit --role=control --path=/home/{$user}/orbit --source-archive=/tmp/orbit-source.tar.gz --bin=/usr/local/bin/orbit --php={$options->phpVersion}", timeoutSeconds: $options->timeoutSeconds);
        $this->ssh($ip, $privateKeyPath, "sudo -iu {$user} bash -lc 'orbit --version | grep -F Orbit'");
    }

    private function prepareGateway(string $ip, string $privateKeyPath, HcloudE2EImagePreparationOptions $options): void
    {
        $this->ssh($ip, $privateKeyPath, $this->ensureSudoUserCommand('orbit'));
        $this->ssh($ip, $privateKeyPath, 'chmod +x /tmp/install-orbit && chown orbit:orbit /tmp/orbit-source.tar.gz /tmp/install-orbit');
        $this->ssh($ip, $privateKeyPath, "/tmp/install-orbit --role=gateway --path=/home/orbit/orbit --source-archive=/tmp/orbit-source.tar.gz --bin=/usr/local/bin/orbit --php={$options->phpVersion}", timeoutSeconds: $options->timeoutSeconds);
        $this->ssh($ip, $privateKeyPath, "sudo -iu orbit bash -lc 'orbit --version | grep -F Orbit'");
        $this->ssh($ip, $privateKeyPath, "sudo -iu orbit bash -lc 'cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2'");
    }

    private function prepareDevapp(string $ip, string $privateKeyPath, HcloudE2EImagePreparationOptions $options): void
    {
        $this->ssh($ip, $privateKeyPath, $this->ensureSudoUserCommand('orbit'));
        $this->ssh($ip, $privateKeyPath, 'chmod +x /tmp/install-orbit && chown orbit:orbit /tmp/orbit-source.tar.gz /tmp/install-orbit');
        $this->ssh($ip, $privateKeyPath, "/tmp/install-orbit --role=app --path=/home/orbit/orbit --source-archive=/tmp/orbit-source.tar.gz --bin=/usr/local/bin/orbit --php={$options->phpVersion}", timeoutSeconds: $options->timeoutSeconds);
        $this->ssh($ip, $privateKeyPath, "sudo -iu orbit bash -lc 'orbit --version | grep -F Orbit'");
    }

    private function cleanImageState(string $ip, string $privateKeyPath, HcloudE2EImagePreparationOptions $options): void
    {
        $this->ssh($ip, $privateKeyPath, sprintf(
            'rm -f /tmp/orbit-source.tar.gz /tmp/install-orbit /home/%1$s/.ssh/authorized_keys /home/%2$s/.ssh/authorized_keys /home/orbit/.ssh/authorized_keys; for user in %1$s %2$s orbit; do if id -u "$user" >/dev/null 2>&1; then install -d -m 700 -o "$user" -g "$user" "/home/$user/.ssh"; touch "/home/$user/.ssh/authorized_keys"; chown "$user:$user" "/home/$user/.ssh/authorized_keys"; chmod 600 "/home/$user/.ssh/authorized_keys"; fi; done; rm -f /etc/machine-id; touch /etc/machine-id; cloud-init clean --logs --seed || true',
            $options->bootstrapUser,
            $options->controlUser,
        ));
    }

    private function snapshot(string $serverName, string $role, string $runId, HcloudE2EImagePreparationOptions $options): void
    {
        $this->run(sprintf('hcloud server shutdown --wait --wait-timeout 120s %s', escapeshellarg($serverName)), timeoutSeconds: 180, allowFailure: true);

        $this->run(sprintf(
            'hcloud server create-image --type snapshot --description %s --label orbit-e2e=true --label orbit-e2e-run=%s --label orbit-e2e-image=%s %s',
            escapeshellarg($this->descriptionFor($role, $options)),
            escapeshellarg($runId),
            escapeshellarg($role),
            escapeshellarg($serverName),
        ), timeoutSeconds: $options->timeoutSeconds);
    }

    private function ensureSudoUserCommand(string $user): string
    {
        return "id -u {$user} >/dev/null 2>&1 || useradd -m -s /bin/bash {$user}; usermod -p '*' {$user}; usermod -aG sudo {$user}; printf '{$user} ALL=(ALL) NOPASSWD:ALL\n' > /etc/sudoers.d/99-{$user}; chmod 440 /etc/sudoers.d/99-{$user}; install -d -m 700 -o {$user} -g {$user} /home/{$user}/.ssh";
    }

    private function ssh(string $ip, string $privateKeyPath, string $command, ?int $timeoutSeconds = null): void
    {
        $this->run(sprintf(
            'ssh -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
            escapeshellarg($privateKeyPath),
            escapeshellarg("root@{$ip}"),
            escapeshellarg($command),
        ), timeoutSeconds: $timeoutSeconds);
    }

    private function cloudInit(HcloudE2EImagePreparationOptions $options): string
    {
        return <<<YAML
#cloud-config
users:
  - default
  - name: {$options->bootstrapUser}
    groups: sudo
    shell: /bin/bash
    sudo: ALL=(ALL) NOPASSWD:ALL
  - name: {$options->controlUser}
    groups: sudo
    shell: /bin/bash
    sudo: ALL=(ALL) NOPASSWD:ALL
ssh_pwauth: false
YAML;
    }

    private function descriptionFor(string $role, HcloudE2EImagePreparationOptions $options): string
    {
        return match ($role) {
            'control' => $options->controlDescription,
            'gateway' => $options->gatewayDescription,
            'devapp' => $options->devappDescription,
            default => throw new RuntimeException("Unknown hcloud image role [{$role}]."),
        };
    }

    private function run(string $command, ?int $timeoutSeconds = null, bool $allowFailure = false): string
    {
        $result = Process::timeout($timeoutSeconds ?? 600)->run($command);

        if (! $result->successful() && ! $allowFailure) {
            throw new RuntimeException("Command failed: {$command}\n{$result->errorOutput()}");
        }

        return $result->output();
    }
}
