<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class OrbitHostInstaller
{
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

            $scriptUpload = $this->scp(base_path('bin/install-orbit'), $sshUser, $host, $remoteInstaller);

            if (! $scriptUpload->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $scriptUpload->output(),
                    errorOutput: $scriptUpload->errorOutput(),
                );
            }

            $archiveUpload = $this->scp($localArchive, $sshUser, $host, $remoteArchive);

            if (! $archiveUpload->successful()) {
                return new OrbitHostInstallResult(
                    successful: false,
                    output: $archiveUpload->output(),
                    errorOutput: $archiveUpload->errorOutput(),
                );
            }

            if ($sshUser !== $runtimeUser) {
                $chown = Process::timeout(30)->run(sprintf(
                    'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s@%s %s',
                    escapeshellarg($sshUser),
                    escapeshellarg($host),
                    escapeshellarg("sudo chown {$runtimeUser}:{$runtimeUser} {$remoteInstaller} {$remoteArchive}"),
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

            $command = $sshUser === $runtimeUser
                ? $installCommand
                : sprintf('sudo su - %s -c %s', escapeshellarg($runtimeUser), escapeshellarg($installCommand));

            $installation = Process::timeout(900)->run(sprintf(
                'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s@%s %s',
                escapeshellarg($sshUser),
                escapeshellarg($host),
                escapeshellarg($command),
            ));

            return new OrbitHostInstallResult(
                successful: $installation->successful(),
                output: $installation->output(),
                errorOutput: $installation->errorOutput(),
            );
        } finally {
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
printf '%%s ALL=(ALL:ALL) NOPASSWD:ALL\n' "$USER" | sudo tee /etc/sudoers.d/99-orbit > /dev/null
sudo chmod 440 /etc/sudoers.d/99-orbit
SCRIPT,
            escapeshellarg($runtimeUser),
        );

        return Process::timeout(60)->run(sprintf(
            'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s@%s %s',
            escapeshellarg($sshUser),
            escapeshellarg($host),
            escapeshellarg($script),
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

    private function scp(string $source, string $sshUser, string $host, string $destination): ProcessResult
    {
        return Process::timeout(120)->run(sprintf(
            'scp -o BatchMode=yes -o StrictHostKeyChecking=accept-new %s %s@%s:%s',
            escapeshellarg($source),
            escapeshellarg($sshUser),
            escapeshellarg($host),
            escapeshellarg($destination),
        ));
    }
}
