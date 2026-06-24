<?php

declare(strict_types=1);

namespace App\Services\E2E;

use App\E2E\Support\IncusHost;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class IncusImageDistributor
{
    public function __construct(
        private readonly IncusHost $sourceHost,
    ) {}

    /**
     * @param  list<IncusHost>  $targetHosts
     * @return list<array{host: string, role: string, alias: string, action: string}>
     */
    public function distribute(string $alias, array $targetHosts): array
    {
        $targets = array_values(array_filter(
            $targetHosts,
            fn (IncusHost $targetHost): bool => ! $this->sameHost($this->sourceHost, $targetHost),
        ));

        if ($targets === []) {
            return [];
        }

        $localDirectory = $this->createLocalWorkDirectory();
        $sourceDirectory = null;

        try {
            $sourceDirectory = $this->createRemoteWorkDirectory(
                $this->sourceHost,
                '/tmp/orbit-e2e-image-export-XXXXXX',
            );
            $sourceArchive = "{$sourceDirectory}/image-export.tar.gz";
            $localArchive = "{$localDirectory}/image-export.tar.gz";

            $this->exportImage($alias, $sourceDirectory, $sourceArchive);
            $this->copyFromHost($this->sourceHost, $sourceArchive, $localArchive);

            $imports = [];

            foreach ($targets as $targetHost) {
                $this->importImage($targetHost, $alias, $localArchive);

                $imports[] = [
                    'host' => $targetHost->config->host,
                    'role' => 'base',
                    'alias' => $alias,
                    'action' => 'imported',
                ];
            }

            return $imports;
        } finally {
            if ($sourceDirectory !== null) {
                $this->sourceHost->run('rm -rf '.escapeshellarg($sourceDirectory), timeoutSeconds: 60);
            }

            Process::timeout(60)->run('rm -rf '.escapeshellarg($localDirectory));
        }
    }

    private function createLocalWorkDirectory(): string
    {
        $directory = sys_get_temp_dir().'/orbit-e2e-image-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0755, true)) {
            throw new RuntimeException("Could not create local image export directory: {$directory}");
        }

        return $directory;
    }

    private function createRemoteWorkDirectory(IncusHost $host, string $template): string
    {
        $result = $host->run('mktemp -d '.escapeshellarg($template), timeoutSeconds: 30);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Could not create image export directory on {$host->config->host}: {$result->errorOutput()}",
            );
        }

        $directory = trim($result->output());

        if ($directory === '') {
            throw new RuntimeException("Image export directory on {$host->config->host} was empty.");
        }

        return $directory;
    }

    private function exportImage(string $alias, string $sourceDirectory, string $sourceArchive): void
    {
        $result = $this->sourceHost->run(sprintf(
            'cd %s && rm -f image-export.tar.gz && incus image export %s image >/dev/null && tar -czf %s image*',
            escapeshellarg($sourceDirectory),
            escapeshellarg($alias),
            escapeshellarg($sourceArchive),
        ), timeoutSeconds: $this->sourceHost->config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Could not export Incus image [{$alias}] on {$this->sourceHost->config->host}: {$result->errorOutput()}",
            );
        }
    }

    private function copyFromHost(IncusHost $host, string $remotePath, string $localPath): void
    {
        $result = Process::timeout($host->config->timeoutSeconds)->run(sprintf(
            'scp -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s:%s %s',
            escapeshellarg($host->config->host),
            escapeshellarg($remotePath),
            escapeshellarg($localPath),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                "Could not copy image archive from {$host->config->host}:{$remotePath}: {$result->errorOutput()}",
            );
        }
    }

    private function importImage(IncusHost $targetHost, string $alias, string $localArchive): void
    {
        $targetDirectory = $this->createRemoteWorkDirectory($targetHost, '/tmp/orbit-e2e-image-import-XXXXXX');

        try {
            $remoteArchive = "{$targetDirectory}/image-export.tar.gz";
            $this->copyToHost($targetHost, $localArchive, $remoteArchive);

            $result = $targetHost->run(
                $this->importCommand($alias, $targetDirectory, $remoteArchive),
                timeoutSeconds: $targetHost->config->timeoutSeconds,
            );

            if (! $result->successful()) {
                throw new RuntimeException(
                    "Could not import Incus image [{$alias}] on {$targetHost->config->host}: {$result->errorOutput()}",
                );
            }
        } finally {
            $targetHost->run('rm -rf '.escapeshellarg($targetDirectory), timeoutSeconds: 60);
        }
    }

    private function copyToHost(IncusHost $host, string $localPath, string $remotePath): void
    {
        $result = Process::timeout($host->config->timeoutSeconds)->run(sprintf(
            'scp -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null %s %s:%s',
            escapeshellarg($localPath),
            escapeshellarg($host->config->host),
            escapeshellarg($remotePath),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                "Could not copy image archive to {$host->config->host}:{$remotePath}: {$result->errorOutput()}",
            );
        }
    }

    private function importCommand(string $alias, string $targetDirectory, string $remoteArchive): string
    {
        $alias = escapeshellarg($alias);
        $targetDirectory = escapeshellarg($targetDirectory);
        $remoteArchive = escapeshellarg($remoteArchive);

        return <<<BASH
            mkdir -p {$targetDirectory}/import
            tar -C {$targetDirectory}/import -xzf {$remoteArchive}
            metadata_file=\$(find {$targetDirectory}/import -maxdepth 1 -type f \( -name '*.tar.xz' -o -name '*.tar.gz' -o -name '*.tar' \) | sort | head -n 1)
            test -n "\${metadata_file}"
            image_files=()
            while IFS= read -r image_file; do
                image_files+=("\${image_file}")
            done < <(find {$targetDirectory}/import -maxdepth 1 -type f ! -path "\${metadata_file}" | sort)
            incus image delete {$alias} >/dev/null 2>&1 || true
            incus image import "\${metadata_file}" "\${image_files[@]}" --alias {$alias} >/dev/null
            BASH;
    }

    private function sameHost(IncusHost $sourceHost, IncusHost $targetHost): bool
    {
        return strtolower($sourceHost->config->host) === strtolower($targetHost->config->host);
    }
}
