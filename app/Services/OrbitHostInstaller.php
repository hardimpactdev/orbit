<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class OrbitHostInstaller
{
    public function install(string $host, string $sshUser, string $role): OrbitHostInstallResult
    {
        $localArchive = $this->buildSourceArchive();
        $remotePrefix = '/tmp/orbit-install-'.Str::lower(Str::random(8));
        $remoteArchive = "{$remotePrefix}.tar.gz";
        $remoteInstaller = "{$remotePrefix}.sh";

        try {
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

            $remoteHome = $sshUser === 'root' ? '/root' : "/home/{$sshUser}";
            $command = sprintf(
                "set -e; trap 'rm -f %s %s' EXIT; bash %s --role=%s --path=%s --source-archive=%s",
                escapeshellarg($remoteInstaller),
                escapeshellarg($remoteArchive),
                escapeshellarg($remoteInstaller),
                escapeshellarg($role),
                escapeshellarg("{$remoteHome}/orbit"),
                escapeshellarg($remoteArchive),
            );

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
