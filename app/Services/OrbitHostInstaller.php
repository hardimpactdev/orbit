<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Node;
use App\Services\RemoteShell\SshCommandBuilder;
use App\Services\Security\HomeDirectoryLockdownInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SysctlBaselineInstaller;
use App\Services\Security\UnattendedUpgradesInstaller;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class OrbitHostInstaller
{
    private ?Node $pinnedNode = null;

    public function usePinnedNode(?Node $node): void
    {
        $this->pinnedNode = $node;
    }

    public function install(string $host, string $sshUser, string $runtimeUser = 'orbit'): OrbitHostInstallResult
    {
        $localArchive = $this->buildSourceArchive();
        $remotePrefix = '/tmp/orbit-install-'.Str::lower(Str::random(8));
        $remoteArchive = "{$remotePrefix}.tar.gz";
        $remoteInstaller = "{$remotePrefix}.sh";

        try {
            $userCreated = $this->createRuntimeUser($host, $sshUser, $runtimeUser);

            if (! $userCreated->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $userCreated->output(),
                    errorOutput: $userCreated->errorOutput(),
                );
            }

            $executionUser = $this->pinnedNode instanceof Node ? $runtimeUser : $sshUser;

            $scriptUpload = $this->scp(base_path('bin/install-orbit'), $executionUser, $host, $remoteInstaller);

            if (! $scriptUpload->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $scriptUpload->output(),
                    errorOutput: $scriptUpload->errorOutput(),
                );
            }

            $archiveUpload = $this->scp($localArchive, $executionUser, $host, $remoteArchive);

            if (! $archiveUpload->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $archiveUpload->output(),
                    errorOutput: $archiveUpload->errorOutput(),
                );
            }

            if (! $this->pinnedNode instanceof Node && $sshUser !== $runtimeUser) {
                $chown = Process::timeout(30)->run($this->ssh(
                    user: $sshUser,
                    host: $host,
                    command: "sudo chown {$runtimeUser}:{$runtimeUser} {$remoteInstaller} {$remoteArchive}",
                ));

                if (! $chown->successful()) {
                    return new OrbitHostInstallResult(
                        successful: false,
                        output: $chown->output(),
                        errorOutput: $chown->errorOutput(),
                    );
                }
            }

            $remoteHome = $runtimeUser === 'root' ? '/root' : "/home/{$runtimeUser}";
            $installCommand = sprintf(
                "set -e; trap 'rm -f %s %s' EXIT; bash %s --path=%s --source-archive=%s",
                escapeshellarg($remoteInstaller),
                escapeshellarg($remoteArchive),
                escapeshellarg($remoteInstaller),
                escapeshellarg("{$remoteHome}/orbit"),
                escapeshellarg($remoteArchive),
            );

            $command = $executionUser === $runtimeUser
                ? $installCommand
                : sprintf('sudo su - %s -c %s', escapeshellarg($runtimeUser), escapeshellarg($installCommand));

            $installation = Process::timeout(900)->run($this->ssh(
                user: $executionUser,
                host: $host,
                command: $command,
            ));

            if (! $installation->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $installation->output(),
                    errorOutput: $installation->errorOutput(),
                );
            }

            $securityBaseline = $this->installSecurityBaseline($host, $runtimeUser);

            if ($securityBaseline instanceof OrbitHostInstallResult && ! $securityBaseline->successful) {
                return $securityBaseline;
            }

            return new OrbitHostInstallResult(
                successful: true,
                output: $installation->output(),
                errorOutput: $installation->errorOutput(),
            );
        } finally {
            $this->pinnedNode = null;
            @unlink($localArchive);
        }
    }

    private function createRuntimeUser(string $host, string $sshUser, string $runtimeUser): ProcessResult
    {
        $script = sprintf(
            <<<'SCRIPT'
set -e
USER=%s
if ! id -u "$USER" >/dev/null 2>&1; then
    sudo useradd -m -s /bin/bash "$USER"
fi
sudo usermod -s /bin/bash "$USER" 2>/dev/null || true
sudo usermod -p '*' "$USER" 2>/dev/null || true
sudo usermod -aG sudo "$USER" 2>/dev/null || true
if [ ! -d "/home/$USER" ]; then
    sudo mkdir -p "/home/$USER"
    sudo chown "$USER:$USER" "/home/$USER"
fi
sudo install -d -m 700 -o "$USER" -g "$USER" "/home/$USER/.ssh"
TARGET_KEYS="/home/$USER/.ssh/authorized_keys"
BOOTSTRAP_KEYS="${HOME:-}/.ssh/authorized_keys"
if [ "$(id -u)" -eq 0 ]; then
    BOOTSTRAP_KEYS="/root/.ssh/authorized_keys"
fi
if [ -s "$BOOTSTRAP_KEYS" ]; then
    sudo touch "$TARGET_KEYS"
    sudo chown "$USER:$USER" "$TARGET_KEYS"
    sudo chmod 600 "$TARGET_KEYS"
    while IFS= read -r key; do
        if [ -n "$key" ] && ! sudo grep -qxF "$key" "$TARGET_KEYS"; then
            printf '%%s\n' "$key" | sudo tee -a "$TARGET_KEYS" > /dev/null
        fi
    done < "$BOOTSTRAP_KEYS"
fi
sudo chown -R "$USER:$USER" "/home/$USER/.ssh"
sudo chmod 700 "/home/$USER/.ssh"
if [ -f "$TARGET_KEYS" ]; then
    sudo chmod 600 "$TARGET_KEYS"
fi
printf '%%s ALL=(ALL:ALL) NOPASSWD:ALL\n' "$USER" | sudo tee /etc/sudoers.d/99-orbit > /dev/null
sudo chmod 440 /etc/sudoers.d/99-orbit
SCRIPT,
            escapeshellarg($runtimeUser),
        );

        return Process::timeout(60)->run($this->ssh(
            user: $sshUser,
            host: $host,
            command: $script,
        ));
    }

    private function buildSourceArchive(): string
    {
        $archive = '/tmp/orbit-source-'.Str::lower(Str::random(8)).'.tar.gz';

        $result = Process::timeout(120)->run(sprintf(
            "tar --exclude='.git' --exclude='node_modules' --exclude='vendor' --exclude='storage/logs/*' --exclude='storage/framework/cache/*' --exclude='storage/framework/sessions/*' --exclude='storage/framework/views/*' --exclude='database/*.sqlite*' --exclude='.env' -czf %s -C %s .",
            escapeshellarg($archive),
            escapeshellarg(base_path()),
        ));

        if (! $result->successful()) {
            @unlink($archive);

            throw new \RuntimeException('Failed to build Orbit source archive: '.trim($result->errorOutput()));
        }

        return $archive;
    }

    private function installSecurityBaseline(string $host, string $runtimeUser): ?OrbitHostInstallResult
    {
        if (! $this->pinnedNode instanceof Node) {
            return null;
        }

        foreach ($this->securityBaselineScripts($this->pinnedNode) as $name => $script) {
            $result = Process::timeout(900)->run($this->ssh(
                user: $runtimeUser,
                host: $host,
                command: $script,
            ));

            if ($result->successful()) {
                continue;
            }

            return new OrbitHostInstallResult(
                successful: false,
                output: $result->output(),
                errorOutput: trim("Security baseline [{$name}] failed.\n".$result->errorOutput()),
            );
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function securityBaselineScripts(Node $node): array
    {
        return [
            'home' => app(HomeDirectoryLockdownInstaller::class)->script(),
            'sysctl' => app(SysctlBaselineInstaller::class)->script(),
            'sshd' => app(SshdHardenedInstaller::class)->script($node),
            'unattended_upgrades' => app(UnattendedUpgradesInstaller::class)->script(),
        ];
    }

    private function scp(string $source, string $sshUser, string $host, string $destination): ProcessResult
    {
        if ($this->pinnedNode instanceof Node) {
            return Process::timeout(120)->run(app(SshCommandBuilder::class)->scpToNode(
                node: $this->pinnedNode,
                source: $source,
                destination: $destination,
                loginUser: $sshUser,
                options: [
                    'batch_mode' => true,
                    'strict_host_key_checking' => 'yes',
                    'prefer_public_host' => true,
                ],
            ));
        }

        return Process::timeout(120)->run(app(SshCommandBuilder::class)->scpTo(
            source: $source,
            user: $sshUser,
            host: $host,
            destination: $destination,
            options: ['batch_mode' => true],
        ));
    }

    private function ssh(string $user, string $host, string $command): string
    {
        if ($this->pinnedNode instanceof Node) {
            return app(SshCommandBuilder::class)->enforceForNode(
                node: $this->pinnedNode,
                remoteCommand: $command,
                loginUser: $user,
                options: [
                    'batch_mode' => true,
                    'prefer_public_host' => true,
                ],
            );
        }

        return app(SshCommandBuilder::class)->ssh(
            user: $user,
            host: $host,
            remoteCommand: $command,
            options: ['batch_mode' => true],
        );
    }
}
