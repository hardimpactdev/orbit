<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;

final class IncusInstance implements E2EInstance
{
    private ?string $ipv4 = null;

    public function __construct(
        private readonly IncusHost $host,
        private readonly string $name,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->host->run(sprintf(
            'incus exec %s -- sh -lc %s',
            escapeshellarg($this->name),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->host->run(sprintf(
            'ssh -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
            escapeshellarg($keyPair->privateKeyPath),
            escapeshellarg("{$user}@{$this->waitForIpv4()}"),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function authorizeSsh(string $user, SshKeyPair $keyPair): void
    {
        $this->exec(sprintf(
            'install -d -m 700 -o %1$s -g %1$s /home/%1$s/.ssh',
            escapeshellarg($user),
        ));

        $push = $this->host->run(sprintf(
            'incus file push %s %s',
            escapeshellarg($keyPair->publicKeyPath),
            escapeshellarg("{$this->name}/home/{$user}/.ssh/authorized_keys"),
        ));

        if (! $push->successful()) {
            throw new \RuntimeException("Could not push SSH public key into {$this->name}: {$push->errorOutput()}");
        }

        $result = $this->exec(sprintf(
            'chown %1$s:%1$s /home/%1$s/.ssh/authorized_keys && chmod 600 /home/%1$s/.ssh/authorized_keys && usermod -p \'*\' %1$s && (systemctl start ssh || systemctl start sshd || true)',
            escapeshellarg($user),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not prepare SSH access on {$this->name}: {$result->errorOutput()}");
        }
    }

    public function copyFileToInstance(string $sourcePath, string $targetPath): void
    {
        $result = $this->host->run(sprintf(
            'incus file push %s %s',
            escapeshellarg($sourcePath),
            escapeshellarg("{$this->name}{$targetPath}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not copy {$sourcePath} into {$this->name}: {$result->errorOutput()}");
        }
    }

    public function waitForAgent(): void
    {
        $deadline = time() + $this->host->config->timeoutSeconds;

        while (time() < $deadline) {
            if ($this->host->run(sprintf('incus exec %s -- true', escapeshellarg($this->name)), timeoutSeconds: 10)->successful()) {
                return;
            }

            sleep(2);
        }

        throw new \RuntimeException("Incus agent never became ready on {$this->name}.");
    }

    public function waitForIpv4(): string
    {
        if ($this->ipv4 !== null) {
            return $this->ipv4;
        }

        $deadline = time() + $this->host->config->timeoutSeconds;

        while (time() < $deadline) {
            $ipv4 = $this->ipv4();

            if ($ipv4 !== null) {
                $this->ipv4 = $ipv4;

                return $ipv4;
            }

            sleep(2);
        }

        throw new \RuntimeException("Instance {$this->name} did not receive an IPv4 address.");
    }

    public function waitForSsh(string $user, SshKeyPair $keyPair): void
    {
        $deadline = time() + $this->host->config->timeoutSeconds;

        while (time() < $deadline) {
            if ($this->ssh($user, $keyPair, 'test "$(uname -s)" = Linux && test -r /etc/os-release', timeoutSeconds: 10)->successful()) {
                return;
            }

            sleep(3);
        }

        throw new \RuntimeException("SSH never became ready on {$this->name}.");
    }

    public function delete(): void
    {
        $this->host->run(sprintf(
            'incus delete --force %s >/dev/null 2>&1 || true',
            escapeshellarg($this->name),
        ), timeoutSeconds: 120);
    }

    public function stop(): void
    {
        $this->ipv4 = null;

        $result = $this->host->stopInstance($this->name);

        if (! $result->successful()) {
            throw new \RuntimeException("Could not stop {$this->name}: {$result->errorOutput()}");
        }
    }

    public function start(): void
    {
        $result = $this->host->startInstance($this->name);

        if (! $result->successful()) {
            throw new \RuntimeException("Could not start {$this->name}: {$result->errorOutput()}");
        }
    }

    public function snapshot(string $snapshot): void
    {
        $result = $this->host->snapshotInstance($this->name, $snapshot);

        if (! $result->successful()) {
            throw new \RuntimeException("Could not snapshot {$this->name} as {$snapshot}: {$result->errorOutput()}");
        }
    }

    public function restoreSnapshot(string $snapshot): void
    {
        $result = $this->host->restoreSnapshot($this->name, $snapshot);

        if (! $result->successful()) {
            throw new \RuntimeException("Could not restore {$this->name} from {$snapshot}: {$result->errorOutput()}");
        }
    }

    private function ipv4(): ?string
    {
        $result = $this->host->run(sprintf(
            "incus list %s --format csv -c 4 | grep -Eo '([0-9]{1,3}\.){3}[0-9]{1,3}' | head -n 1 || true",
            escapeshellarg($this->name),
        ), timeoutSeconds: 10);

        $output = trim($result->output());

        return $output !== '' ? $output : null;
    }
}
