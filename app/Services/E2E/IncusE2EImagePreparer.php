<?php

declare(strict_types=1);

namespace App\Services\E2E;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\E2E\Support\IncusHost;

/**
 * Orchestrates building reusable Incus base images on a remote Incus host.
 *
 * The blank role is built fully on the host (cloud-init bootstraps a sudoers
 * user). Every other role is built from the blank image: a tarball of the
 * local Orbit source plus bin/install-orbit are pushed to the host, then into
 * the VM, and install-orbit runs as the role-specific target user.
 *
 * All Incus operations execute on the host via $host->run(). Local Process
 * calls are used only for tar (the source archive) and scp (pushing the
 * archive to a remote host).
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
                'control' => $this->buildFromBlank($options, role: 'control', orbitRole: 'control', targetUser: $options->controlUser, alias: $options->controlImageAlias, postInstall: []),
                'gateway' => $this->buildFromBlank($options, role: 'gateway', orbitRole: 'gateway', targetUser: 'orbit', alias: $options->gatewayImageAlias, postInstall: [
                    'cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2',
                ]),
                'devapp' => $this->buildFromBlank($options, role: 'devapp', orbitRole: 'app', targetUser: 'orbit', alias: $options->devappImageAlias, postInstall: []),
                'prodapp' => $this->buildFromBlank($options, role: 'prodapp', orbitRole: 'app', targetUser: 'orbit', alias: $options->prodappImageAlias, postInstall: []),
                default => throw new RuntimeException("Unknown role [{$role}]."),
            };
        }

        return new IncusE2EImagePreparationResult($built);
    }

    private function aliasFor(string $role, IncusE2EImagePreparationOptions $options): string
    {
        return match ($role) {
            'blank' => $options->blankImageAlias,
            'control' => $options->controlImageAlias,
            'gateway' => $options->gatewayImageAlias,
            'devapp' => $options->devappImageAlias,
            'prodapp' => $options->prodappImageAlias,
            default => "orbit-ready-{$role}",
        };
    }

    /**
     * @return array{role: string, alias: string, action: string}
     */
    private function buildBlank(IncusE2EImagePreparationOptions $options): array
    {
        $runId = $this->newRunId();
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

            $this->cleanBlankImageState($instanceName, $options->bootstrapUser);
            $this->stopInstance($instanceName);
            $this->publishImage($instanceName, $options->blankImageAlias);

            return [
                'role' => 'blank',
                'alias' => $options->blankImageAlias,
                'action' => 'built',
            ];
        } finally {
            $this->cleanupRemote($tempInstance, $remoteWorkDir);
        }
    }

    /**
     * @param  list<string>  $postInstall  Extra shell commands to run as the target user after install-orbit.
     * @return array{role: string, alias: string, action: string}
     */
    private function buildFromBlank(
        IncusE2EImagePreparationOptions $options,
        string $role,
        string $orbitRole,
        string $targetUser,
        string $alias,
        array $postInstall,
    ): array {
        $runId = $this->newRunId();
        $instanceName = "orbit-e2e-{$runId}-prepare-{$role}";
        $remoteWorkDir = $this->createRemoteWorkDir($instanceName);
        $tempInstance = null;
        $localTarball = null;

        try {
            if (! $this->host->imageExists($options->blankImageAlias)) {
                throw new RuntimeException(
                    "Blank image [{$options->blankImageAlias}] missing on host. Run --role=blank --force first."
                );
            }

            $localTarball = $this->buildSourceArchive();

            $remoteTarball = "{$remoteWorkDir}/orbit-source.tar.gz";
            $remoteInstaller = "{$remoteWorkDir}/install-orbit";
            $this->pushFileToHost($localTarball, $remoteTarball);
            $this->pushFileToHost($options->installScriptPath, $remoteInstaller);

            $this->launchInstanceFromImage($instanceName, $options->blankImageAlias, $options);
            $tempInstance = $instanceName;

            $this->waitForAgent($instanceName, $options->timeoutSeconds);

            $this->ensureSudoUser($instanceName, $targetUser);

            $this->incusFilePush($remoteTarball, "{$instanceName}/tmp/orbit-source.tar.gz");
            $this->incusFilePush($remoteInstaller, "{$instanceName}/tmp/install-orbit");

            $this->execAsRoot($instanceName, sprintf(
                'chmod +x /tmp/install-orbit && chown %1$s:%1$s /tmp/orbit-source.tar.gz /tmp/install-orbit',
                $targetUser,
            ));

            $this->execAsUser($instanceName, $targetUser, sprintf(
                '/tmp/install-orbit --role=%s --path=/home/%s/orbit --source-archive=/tmp/orbit-source.tar.gz --bin=/usr/local/bin/orbit',
                $orbitRole,
                $targetUser,
            ), timeoutSeconds: $options->timeoutSeconds);

            $this->execAsUser($instanceName, $targetUser, "orbit --version | grep -F 'Orbit'");

            foreach ($postInstall as $step) {
                $this->execAsUser($instanceName, $targetUser, $step, timeoutSeconds: $options->timeoutSeconds);
            }

            $this->cleanRoleImageState($instanceName, $options->bootstrapUser, $targetUser);
            $this->stopInstance($instanceName);
            $this->publishImage($instanceName, $alias);

            return [
                'role' => $role,
                'alias' => $alias,
                'action' => 'built',
            ];
        } finally {
            $this->cleanupRemote($tempInstance, $remoteWorkDir);

            if ($localTarball !== null && is_file($localTarball)) {
                @unlink($localTarball);
            }
        }
    }

    private function newRunId(): string
    {
        return date('YmdHis').'-'.getmypid().'-'.bin2hex(random_bytes(3));
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

    private function launchInstanceFromImage(
        string $name,
        string $imageAlias,
        IncusE2EImagePreparationOptions $options,
    ): void {
        $launch = $this->host->run(sprintf(
            'incus launch %s %s --vm --config=limits.cpu=%s --config=limits.memory=%s >/dev/null',
            escapeshellarg($imageAlias),
            escapeshellarg($name),
            escapeshellarg((string) $options->cpus),
            escapeshellarg($options->memory),
        ), timeoutSeconds: $options->timeoutSeconds);

        if (! $launch->successful()) {
            throw new RuntimeException("Failed to launch [{$name}] from [{$imageAlias}]: {$launch->errorOutput()}");
        }
    }

    private function waitForAgent(string $instanceName, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $result = $this->host->run(
                sprintf('incus exec %s -- true', escapeshellarg($instanceName)),
                timeoutSeconds: 10,
            );

            if ($result->successful()) {
                return;
            }

            sleep(2);
        }

        throw new RuntimeException("Incus agent never became ready on [{$instanceName}].");
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

    private function ensureSudoUser(string $instanceName, string $user): void
    {
        if ($user === 'orbit') {
            // orbit user is allowed; the legacy bin/e2e blocked it for the
            // ready-control image, but the gateway/app images explicitly want
            // orbit. We allow it here and let the install-orbit role flag
            // decide what gets configured.
        }

        $this->execAsRoot($instanceName, sprintf(
            'id -u %1$s >/dev/null 2>&1 || useradd -m -s /bin/bash %1$s',
            escapeshellarg($user),
        ));

        $this->execAsRoot($instanceName, sprintf(
            'usermod -p \'*\' %1$s && '
            .'usermod -aG sudo %1$s && '
            .'printf \'%%s ALL=(ALL) NOPASSWD:ALL\n\' %1$s > /etc/sudoers.d/99-%1$s && '
            .'chmod 440 /etc/sudoers.d/99-%1$s && '
            .'install -d -m 700 -o %1$s -g %1$s /home/%1$s/.ssh',
            escapeshellarg($user),
        ));
    }

    private function execAsRoot(string $instanceName, string $command, ?int $timeoutSeconds = null): void
    {
        $result = $this->host->run(sprintf(
            'incus exec %s -- sh -lc %s',
            escapeshellarg($instanceName),
            escapeshellarg($command),
        ), timeoutSeconds: $timeoutSeconds ?? 60);

        if (! $result->successful()) {
            throw new RuntimeException("Command failed on [{$instanceName}]: {$result->errorOutput()}");
        }
    }

    private function execAsUser(string $instanceName, string $user, string $command, ?int $timeoutSeconds = null): void
    {
        $result = $this->host->run(sprintf(
            'incus exec %s -- sudo -iu %s bash -lc %s',
            escapeshellarg($instanceName),
            escapeshellarg($user),
            escapeshellarg($command),
        ), timeoutSeconds: $timeoutSeconds ?? 120);

        if (! $result->successful()) {
            throw new RuntimeException("Command failed on [{$instanceName}] as [{$user}]: {$result->errorOutput()}");
        }
    }

    private function incusFilePush(string $sourcePath, string $target): void
    {
        $result = $this->host->run(sprintf(
            'incus file push %s %s',
            escapeshellarg($sourcePath),
            escapeshellarg($target),
        ), timeoutSeconds: 120);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to push {$sourcePath} into {$target}: {$result->errorOutput()}");
        }
    }

    private function buildSourceArchive(): string
    {
        $tarball = sys_get_temp_dir().'/orbit-source-'.bin2hex(random_bytes(6)).'.tar.gz';

        $excludes = [
            './.git',
            './.env',
            './database/*.sqlite',
            './database/*.sqlite-*',
            './node_modules',
            './storage/framework/cache/data/*',
            './storage/framework/sessions/*',
            './storage/framework/testing/*',
            './storage/framework/views/*',
            './storage/logs/*',
            './vendor',
        ];

        $excludeArgs = implode(' ', array_map(
            fn (string $pattern): string => '--exclude='.escapeshellarg($pattern),
            $excludes,
        ));

        $command = sprintf(
            'COPYFILE_DISABLE=1 tar %s -czf %s -C %s .',
            $excludeArgs,
            escapeshellarg($tarball),
            escapeshellarg(base_path()),
        );

        $result = Process::timeout(300)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to build source archive locally: {$result->errorOutput()}");
        }

        return $tarball;
    }

    private function pushFileToHost(string $localPath, string $remotePath): void
    {
        if (! is_file($localPath)) {
            throw new RuntimeException("Local file not found: {$localPath}");
        }

        $hostName = $this->host->config->host;

        if ($this->isLocalHost($hostName)) {
            if (! @copy($localPath, $remotePath)) {
                throw new RuntimeException("Could not copy {$localPath} to {$remotePath}.");
            }

            return;
        }

        $result = Process::timeout(300)->run(sprintf(
            'scp -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s:%s',
            escapeshellarg($localPath),
            escapeshellarg($hostName),
            escapeshellarg($remotePath),
        ));

        if (! $result->successful()) {
            throw new RuntimeException("Failed to push {$localPath} to {$hostName}:{$remotePath}: {$result->errorOutput()}");
        }
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['', 'localhost', '127.0.0.1', '::1'], true)
            || strtolower($host) === strtolower((string) gethostname());
    }

    private function cleanBlankImageState(string $instanceName, string $bootstrapUser): void
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

        $this->execAsRoot($instanceName, $script);
    }

    private function cleanRoleImageState(string $instanceName, string $bootstrapUser, string $targetUser): void
    {
        $script = sprintf(
            'rm -f /tmp/orbit-source.tar.gz /tmp/install-orbit '
            .'/home/%1$s/.ssh/authorized_keys '
            .'/home/%2$s/.ssh/authorized_keys && '
            .'install -d -m 700 -o %1$s -g %1$s /home/%1$s/.ssh && '
            .'touch /home/%1$s/.ssh/authorized_keys && '
            .'chown %1$s:%1$s /home/%1$s/.ssh/authorized_keys && '
            .'chmod 600 /home/%1$s/.ssh/authorized_keys && '
            .'install -d -m 700 -o %2$s -g %2$s /home/%2$s/.ssh && '
            .'touch /home/%2$s/.ssh/authorized_keys && '
            .'chown %2$s:%2$s /home/%2$s/.ssh/authorized_keys && '
            .'chmod 600 /home/%2$s/.ssh/authorized_keys && '
            .'rm -f /etc/machine-id && '
            .'touch /etc/machine-id && '
            .'cloud-init clean --logs --seed || true',
            $targetUser,
            $bootstrapUser,
        );

        $this->execAsRoot($instanceName, $script);
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

    private function cleanupRemote(?string $tempInstance, string $remoteWorkDir): void
    {
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
