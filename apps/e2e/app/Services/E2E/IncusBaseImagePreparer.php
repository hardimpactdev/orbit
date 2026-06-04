<?php

declare(strict_types=1);

namespace App\Services\E2E;

use App\E2E\Support\IncusHost;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Build the reusable Incus base image (`orbit-base-ubuntu-26.04`) used by the
 * E2E topology lane. The base image holds OS deps, the bootstrap user, the
 * `orbit` user, and the runtime directory tree — but no Orbit source. Source
 * is pushed per topology preparation; see bin/e2e-provision-node.
 *
 * Creates the `orbit` user and pre-installs the apt package list defined by
 * bin/_e2e-deps.sh, so the per-run `bin/install-orbit` invocation inside a
 * base-cloned VM is fast.
 */
class IncusBaseImagePreparer
{
    public function __construct(private readonly IncusHost $host) {}

    /**
     * @return array{role: string, alias: string, action: string}
     */
    public function build(IncusBaseImagePreparationOptions $options): array
    {
        if (! $options->force) {
            return [
                'role' => 'base',
                'alias' => $options->baseImageAlias,
                'action' => 'planned',
            ];
        }

        $runId = $this->newRunId();
        $instanceName = "orbit-e2e-{$runId}-prepare-base";
        $remoteWorkDir = $this->createRemoteWorkDir($instanceName);
        $tempInstance = null;

        try {
            $this->ensureSourceImageExists($options->sourceImage);

            $remotePrivateKey = "{$remoteWorkDir}/id_ed25519";
            $publicKey = $this->createRemoteSshKey($remotePrivateKey, $runId);

            $packages = $this->readPackageList($options->depsScriptPath, '--all');

            $this->launchBaseInstance($instanceName, $options);
            $tempInstance = $instanceName;

            $this->waitForAgent($instanceName, $options->timeoutSeconds);
            $this->provisionBase($instanceName, $remoteWorkDir, $publicKey, $packages, $options);
            $ipv4 = $this->waitForIpv4($instanceName, $options->timeoutSeconds);
            $this->waitForSsh($ipv4, $remotePrivateKey, $options->bootstrapUser, $options->timeoutSeconds);

            $this->cleanBaseImageState($instanceName, $options->bootstrapUser);
            $this->stopInstance($instanceName);
            $this->publishImage($instanceName, $options->baseImageAlias);

            return [
                'role' => 'base',
                'alias' => $options->baseImageAlias,
                'action' => 'built',
            ];
        } finally {
            $this->cleanupRemote($tempInstance, $remoteWorkDir);
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
            escapeshellarg("orbit-e2e-base-{$runId}"),
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

    /**
     * @return list<string>
     */
    private function readPackageList(string $depsScriptPath, string $selector): array
    {
        if (! is_file($depsScriptPath) || ! is_executable($depsScriptPath)) {
            throw new RuntimeException("Deps helper not executable: {$depsScriptPath}");
        }

        $result = Process::timeout(30)->run([$depsScriptPath, $selector]);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to read package list from {$depsScriptPath} {$selector}: {$result->errorOutput()}");
        }

        $packages = array_values(array_filter(
            array_map(trim(...), explode("\n", $result->output())),
            fn (string $package): bool => $package !== '',
        ));

        if ($packages === []) {
            throw new RuntimeException("Deps helper {$depsScriptPath} {$selector} returned an empty package list.");
        }

        return $packages;
    }

    private function launchBaseInstance(string $name, IncusBaseImagePreparationOptions $options): void
    {
        $launch = $this->host->run(sprintf(
            'incus launch %s %s --vm --config=limits.cpu=%s --config=limits.memory=%s >/dev/null',
            escapeshellarg($options->sourceImage),
            escapeshellarg($name),
            escapeshellarg((string) $options->cpus),
            escapeshellarg($options->memory),
        ), timeoutSeconds: $options->timeoutSeconds);

        if (! $launch->successful()) {
            throw new RuntimeException("Failed to launch base instance [{$name}]: {$launch->errorOutput()}");
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

    private function cleanBaseImageState(string $instanceName, string $bootstrapUser): void
    {
        $script = sprintf(
            'rm -f /home/%1$s/.ssh/authorized_keys && '
            .'touch /home/%1$s/.ssh/authorized_keys && '
            .'chown %1$s:%1$s /home/%1$s/.ssh/authorized_keys && '
            .'chmod 600 /home/%1$s/.ssh/authorized_keys && '
            .'install -d -m 700 -o orbit -g orbit /home/orbit/.ssh && '
            .'rm -f /home/orbit/.ssh/authorized_keys && '
            .'touch /home/orbit/.ssh/authorized_keys && '
            .'chown orbit:orbit /home/orbit/.ssh/authorized_keys && '
            .'chmod 600 /home/orbit/.ssh/authorized_keys && '
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
            throw new RuntimeException("Failed to clean state on [{$instanceName}]: {$result->errorOutput()}");
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

    /**
     * Provision the freshly launched base instance over the Incus agent.
     *
     * The fast non-cloud Ubuntu image has no cloud-init, so the previous
     * user-data flow is replaced by a single provisioning script piped to
     * `incus exec ... -- bash`. It performs the same setup the cloud-init
     * config used to: apt/DNS hardening, the package list, the bootstrap and
     * `orbit` users, the bootstrap SSH key, the runtime directory tree, and
     * the php8.5 alternative.
     *
     * @param  list<string>  $packages
     */
    private function provisionBase(
        string $name,
        string $remoteWorkDir,
        string $publicKey,
        array $packages,
        IncusBaseImagePreparationOptions $options,
    ): void {
        $script = $this->provisionScript($options, $publicKey, $packages);
        $localScriptPath = "{$remoteWorkDir}/provision-base.sh";

        $write = $this->host->run(sprintf(
            "cat > %s <<'PROVISIONEOF'\n%s\nPROVISIONEOF\n",
            escapeshellarg($localScriptPath),
            $script,
        ), timeoutSeconds: 30);

        if (! $write->successful()) {
            throw new RuntimeException("Failed to write base provision script on Incus host: {$write->errorOutput()}");
        }

        $provision = $this->host->run(sprintf(
            'cat %s | incus exec %s -- bash',
            escapeshellarg($localScriptPath),
            escapeshellarg($name),
        ), timeoutSeconds: $options->timeoutSeconds);

        if (! $provision->successful()) {
            throw new RuntimeException(
                "Base provisioning failed on [{$name}]: ".trim($provision->output()."\n".$provision->errorOutput())
            );
        }
    }

    /**
     * @param  list<string>  $packages
     */
    private function provisionScript(IncusBaseImagePreparationOptions $options, string $publicKey, array $packages): string
    {
        $packageList = implode(' ', $packages);
        $bootstrapUser = $options->bootstrapUser;
        $publicKeyBase64 = base64_encode($publicKey);

        $lines = [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'export DEBIAN_FRONTEND=noninteractive',
            '',
            '# apt + DNS hardening (was cloud-init bootcmd/apt)',
            'rm -f /etc/resolv.conf',
            "printf 'nameserver 1.1.1.1\\nnameserver 8.8.8.8\\n' > /etc/resolv.conf",
            "printf '%s\\n' 'Acquire::ForceIPv4 \"true\";' 'Acquire::http::Timeout \"10\";' 'Acquire::https::Timeout \"10\";' 'Acquire::Retries \"1\";' > /etc/apt/apt.conf.d/99orbit-e2e-network",
            '',
            '# Package list (was cloud-init packages:)',
            'apt-get update -y',
            "apt-get install -y {$packageList}",
            '',
            '# Bootstrap + orbit users (was cloud-init users:)',
            "id {$bootstrapUser} >/dev/null 2>&1 || useradd --create-home --shell /bin/bash --groups sudo {$bootstrapUser}",
            'id orbit >/dev/null 2>&1 || useradd --create-home --shell /bin/bash --groups sudo orbit',
            "printf '%s ALL=(ALL) NOPASSWD:ALL\\norbit ALL=(ALL) NOPASSWD:ALL\\n' {$bootstrapUser} > /etc/sudoers.d/99-orbit-e2e",
            'chmod 0440 /etc/sudoers.d/99-orbit-e2e',
            "usermod -p '*' {$bootstrapUser}",
            "usermod -p '*' orbit",
            '',
            '# Bootstrap user SSH key (was cloud-init ssh_authorized_keys)',
            "install -d -m 700 -o {$bootstrapUser} -g {$bootstrapUser} /home/{$bootstrapUser}/.ssh",
            "printf '%s' '{$publicKeyBase64}' | base64 -d > /home/{$bootstrapUser}/.ssh/authorized_keys",
            "printf '\\n' >> /home/{$bootstrapUser}/.ssh/authorized_keys",
            "chown {$bootstrapUser}:{$bootstrapUser} /home/{$bootstrapUser}/.ssh/authorized_keys",
            "chmod 600 /home/{$bootstrapUser}/.ssh/authorized_keys",
            '',
            '# Orbit runtime dirs + php8.5 alternative + ssh (was cloud-init runcmd)',
            'install -d -m 700 -o orbit -g orbit /home/orbit/.ssh',
            'install -d -m 755 -o orbit -g orbit /home/orbit/.config',
            'install -d -m 755 -o orbit -g orbit /home/orbit/.config/orbit',
            'update-alternatives --set php /usr/bin/php8.5 || true',
            'systemctl enable --now ssh || systemctl enable --now sshd || true',
            '',
        ];

        return implode("\n", $lines);
    }
}
