<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final class IncusInstance implements E2EInstance, SourceMountedCheckoutInstance
{
    private ?string $ipv4 = null;

    public function __construct(
        private readonly IncusHost $host,
        private readonly string $name,
        private readonly bool $commandTransport = false,
        private readonly bool $sourceMountedCheckout = false,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        $authScript = E2EGitHubAuth::shellInputScript($command);

        if ($authScript !== null) {
            return $this->host->runWithInput(sprintf(
                'incus exec %s -- bash -s',
                escapeshellarg($this->name),
            ), $authScript, $timeoutSeconds);
        }

        return $this->host->run(sprintf(
            'incus exec %s -- sh -lc %s',
            escapeshellarg($this->name),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        $authScript = E2EGitHubAuth::shellInputScript($command);

        if ($this->commandTransport) {
            if ($authScript !== null) {
                return $this->host->runWithInput(sprintf(
                    'incus exec %s -- runuser -u %s -- bash -s',
                    escapeshellarg($this->name),
                    escapeshellarg($user),
                ), $authScript, $timeoutSeconds);
            }

            return $this->host->run(sprintf(
                'incus exec %s -- runuser -u %s -- bash -lc %s',
                escapeshellarg($this->name),
                escapeshellarg($user),
                escapeshellarg($command),
            ), $timeoutSeconds);
        }

        if ($authScript !== null) {
            return $this->host->runWithInput(sprintf(
                'ssh -i %s -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s',
                escapeshellarg($keyPair->privateKeyPath),
                escapeshellarg("{$user}@{$this->waitForIpv4()}"),
                escapeshellarg('bash -s'),
            ), $authScript, $timeoutSeconds);
        }

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

    public function copyLocalFileToInstance(string $sourcePath, string $targetPath): void
    {
        if (! is_file($sourcePath)) {
            throw new \RuntimeException("Local file not found: {$sourcePath}");
        }

        $remotePath = '/tmp/orbit-current-transfer-'.bin2hex(random_bytes(6)).'-'.basename($sourcePath);
        $hostName = $this->host->config->host;

        if ($this->isLocalHost($hostName)) {
            if (! @copy($sourcePath, $remotePath)) {
                throw new \RuntimeException("Could not copy {$sourcePath} to {$remotePath}.");
            }

            if (! @chmod($remotePath, 0644)) {
                throw new \RuntimeException("Could not make {$remotePath} readable.");
            }
        } else {
            $result = Process::timeout(300)->run(sprintf(
                'scp -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s:%s',
                escapeshellarg($sourcePath),
                escapeshellarg($hostName),
                escapeshellarg($remotePath),
            ));

            if (! $result->successful()) {
                throw new \RuntimeException("Could not copy {$sourcePath} to {$hostName}:{$remotePath}: {$result->errorOutput()}");
            }
        }

        try {
            $this->copyFileToInstance($remotePath, $targetPath);
        } finally {
            $this->host->run('rm -f '.escapeshellarg($remotePath), timeoutSeconds: 30);
        }
    }

    public function waitForAgent(): void
    {
        $deadline = time() + $this->host->config->timeoutSeconds;

        while (time() < $deadline) {
            try {
                if ($this->host->run(sprintf('incus exec %s -- true', escapeshellarg($this->name)), timeoutSeconds: 15)->successful()) {
                    return;
                }
            } catch (\Throwable) {
            }

            sleep(2);
        }

        throw new \RuntimeException("Incus agent never became ready on {$this->name}.");
    }

    public function refreshNetworkIdentity(): void
    {
        $this->ipv4 = null;

        $result = $this->exec(
            'rm -f /etc/machine-id /var/lib/dbus/machine-id && systemd-machine-id-setup && systemctl restart systemd-journald && rm -f /run/systemd/netif/leases/* /var/lib/systemd/network/* && (systemctl --no-block restart systemd-networkd 2>/dev/null || systemctl --no-block restart NetworkManager 2>/dev/null || true)',
            timeoutSeconds: 60,
        );

        if (! $result->successful()) {
            throw new \RuntimeException("Could not refresh network identity for {$this->name}: {$result->errorOutput()}");
        }

        $this->ipv4 = null;
    }

    public function waitForIpv4(): string
    {
        if ($this->ipv4 !== null) {
            return $this->ipv4;
        }

        $deadline = time() + $this->host->config->timeoutSeconds;

        while (time() < $deadline) {
            $ipv4 = $this->providerIpv4();

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
        if ($this->commandTransport) {
            $deadline = time() + $this->host->config->timeoutSeconds;

            while (time() < $deadline) {
                try {
                    $result = $this->ssh($user, $keyPair, 'test "$(uname -s)" = Linux && test -r /etc/os-release', timeoutSeconds: 15);

                    if ($result->successful()) {
                        return;
                    }
                } catch (\Throwable) {
                }

                sleep(1);
            }

            throw new \RuntimeException("Incus command transport is not ready for {$user}@{$this->name}.");
        }

        $deadline = time() + $this->host->config->timeoutSeconds;

        while (time() < $deadline) {
            try {
                if ($this->ssh($user, $keyPair, 'test "$(uname -s)" = Linux && test -r /etc/os-release', timeoutSeconds: 15)->successful()) {
                    return;
                }
            } catch (\Throwable) {
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

    public function sourceMountedCheckoutPath(): ?string
    {
        return $this->sourceMountedCheckout ? '/home/orbit/orbit' : null;
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

    public function snapshotStatefully(string $snapshot): void
    {
        $result = $this->host->snapshotStatefulInstance($this->name, $snapshot);

        if (! $result->successful()) {
            throw new \RuntimeException("Could not statefully snapshot {$this->name} as {$snapshot}: {$result->errorOutput()}");
        }
    }

    public function restoreSnapshot(string $snapshot, bool $stateful = false): void
    {
        $this->ipv4 = null;

        $result = $this->host->restoreSnapshot($this->name, $snapshot, $stateful);

        if (! $result->successful()) {
            throw new \RuntimeException("Could not restore {$this->name} from {$snapshot}: {$result->errorOutput()}");
        }
    }

    private function providerIpv4(): ?string
    {
        $guestIpv4 = $this->guestIpv4();

        if ($guestIpv4 !== null) {
            return $guestIpv4;
        }

        try {
            $python = <<<'PY'
import json
import sys

state = json.load(sys.stdin)
ignored = {"docker0", "lo", "wg-orbit", "wg0"}

for interface, details in state.get("network", {}).items():
    if interface in ignored or interface.startswith(("br-", "veth")):
        continue

    for address in details.get("addresses", []):
        if address.get("family") == "inet" and address.get("scope") == "global":
            print(address.get("address", ""))
            raise SystemExit(0)
PY;

            $result = $this->host->run(sprintf(
                "if command -v python3 >/dev/null 2>&1; then\n"
                    .'incus query %s | python3 -c %s'."\n"
                    ."else\n"
                    ."incus list --format csv -c n,4 | awk -F, -v name=%s '\$1 == name {print \$2}' | grep -Ev '\\((wg-orbit|docker0|br-|veth|wg0|lo)' | grep -Eo '([0-9]{1,3}\.){3}[0-9]{1,3}' | head -n 1 || true\n"
                    .'fi',
                escapeshellarg("/1.0/instances/{$this->name}/state"),
                escapeshellarg($python),
                escapeshellarg($this->name),
            ), timeoutSeconds: 30);
        } catch (\Throwable) {
            return null;
        }

        $output = trim($result->output());

        return $output !== '' ? $output : null;
    }

    private function guestIpv4(): ?string
    {
        try {
            $python = <<<'PY'
import json
import sys

addresses = json.load(sys.stdin)
ignored = {"docker0", "lo", "wg-orbit", "wg0"}

for details in addresses:
    interface = details.get("ifname", "")

    if interface in ignored or interface.startswith(("br-", "veth")):
        continue

    for address in details.get("addr_info", []):
        if address.get("family") == "inet" and address.get("scope") == "global":
            print(address.get("local", ""))
            raise SystemExit(0)
PY;

            $result = $this->host->run(sprintf(
                'incus exec %s -- sh -lc %s | python3 -c %s',
                escapeshellarg($this->name),
                escapeshellarg('ip -j -4 address show scope global'),
                escapeshellarg($python),
            ), timeoutSeconds: 30);
        } catch (\Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        return $output !== '' ? $output : null;
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['', 'localhost', '127.0.0.1', '::1'], true)
            || strtolower($host) === strtolower((string) gethostname());
    }
}
