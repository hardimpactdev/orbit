<?php

declare(strict_types=1);

namespace App\Services\E2E;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DockerImageDistributor
{
    public function __construct(
        private readonly string $sourceHost,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * @param  list<array{role: string, image: string}>  $images
     * @param  list<string>  $targetHosts
     * @return list<array{host: string, role: string, image: string, action: string}>
     */
    public function distribute(array $images, array $targetHosts): array
    {
        $targets = array_values(array_filter(
            $targetHosts,
            fn (string $targetHost): bool => ! $this->sameHost($this->sourceHost, $targetHost),
        ));

        if ($targets === [] || $images === []) {
            return [];
        }

        $localDirectory = $this->createLocalWorkDirectory();
        $sourceDirectory = null;

        try {
            $sourceDirectory = $this->createRemoteWorkDirectory($this->sourceHost, '/tmp/orbit-e2e-docker-image-export-XXXXXX');
            $sourceArchive = "{$sourceDirectory}/images.tar.gz";
            $localArchive = "{$localDirectory}/images.tar.gz";
            $imports = [];

            $this->exportImages($images, $sourceArchive);
            $this->copyFromHost($this->sourceHost, $sourceArchive, $localArchive);

            foreach ($targets as $targetHost) {
                $this->importImages($targetHost, $images, $localArchive);

                foreach ($images as $image) {
                    $imports[] = [
                        'host' => $targetHost,
                        'role' => $image['role'],
                        'image' => $image['image'],
                        'action' => 'imported',
                    ];
                }
            }

            return $imports;
        } finally {
            if ($sourceDirectory !== null) {
                $this->runOnHost($this->sourceHost, 'rm -rf '.escapeshellarg($sourceDirectory), timeoutSeconds: 60);
            }

            Process::timeout(60)->run('rm -rf '.escapeshellarg($localDirectory));
        }
    }

    private function createLocalWorkDirectory(): string
    {
        $directory = sys_get_temp_dir().'/orbit-e2e-docker-image-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0755, true)) {
            throw new RuntimeException("Could not create local Docker image export directory: {$directory}");
        }

        return $directory;
    }

    private function createRemoteWorkDirectory(string $host, string $template): string
    {
        $result = $this->runOnHost($host, 'mktemp -d '.escapeshellarg($template), timeoutSeconds: 30);

        if (! $result->successful()) {
            throw new RuntimeException("Could not create Docker image work directory on {$host}: {$result->errorOutput()}");
        }

        $directory = trim($result->output());

        if ($directory === '') {
            throw new RuntimeException("Docker image work directory on {$host} was empty.");
        }

        return $directory;
    }

    /**
     * @param  list<array{role: string, image: string}>  $images
     */
    private function exportImages(array $images, string $sourceArchive): void
    {
        $imageNames = array_column($images, 'image');
        $inspectCommands = array_map(
            fn (string $image): string => sprintf('docker image inspect %s >/dev/null', escapeshellarg($image)),
            $imageNames,
        );
        $escapedImages = implode(' ', array_map(escapeshellarg(...), $imageNames));

        $result = $this->runOnHost($this->sourceHost, sprintf(
            '%s && rm -f %s && docker save %s | gzip -1 > %s',
            implode(' && ', $inspectCommands),
            escapeshellarg($sourceArchive),
            $escapedImages,
            escapeshellarg($sourceArchive),
        ), timeoutSeconds: $this->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException("Could not export Docker images on {$this->sourceHost}: {$result->errorOutput()}");
        }
    }

    /**
     * @param  list<array{role: string, image: string}>  $images
     */
    private function importImages(string $targetHost, array $images, string $localArchive): void
    {
        $targetDirectory = $this->createRemoteWorkDirectory($targetHost, '/tmp/orbit-e2e-docker-image-import-XXXXXX');

        try {
            $remoteArchive = "{$targetDirectory}/images.tar.gz";

            $this->copyToHost($targetHost, $localArchive, $remoteArchive);

            $inspectCommands = array_map(
                fn (array $image): string => sprintf('docker image inspect %s >/dev/null', escapeshellarg($image['image'])),
                $images,
            );

            $result = $this->runOnHost($targetHost, sprintf(
                'gzip -dc %s | docker load >/dev/null && %s',
                escapeshellarg($remoteArchive),
                implode(' && ', $inspectCommands),
            ), timeoutSeconds: $this->timeoutSeconds);

            if (! $result->successful()) {
                throw new RuntimeException("Could not import Docker images on {$targetHost}: {$result->errorOutput()}");
            }
        } finally {
            $this->runOnHost($targetHost, 'rm -rf '.escapeshellarg($targetDirectory), timeoutSeconds: 60);
        }
    }

    private function copyFromHost(string $host, string $remotePath, string $localPath): void
    {
        if ($this->isLocalHost($host)) {
            $result = Process::timeout($this->timeoutSeconds)->run(sprintf(
                'cp %s %s',
                escapeshellarg($remotePath),
                escapeshellarg($localPath),
            ));
        } else {
            $result = Process::timeout($this->timeoutSeconds)->run(sprintf(
                'scp %s %s %s',
                $this->sshOptions(),
                $this->remotePath($host, $remotePath),
                escapeshellarg($localPath),
            ));
        }

        if (! $result->successful()) {
            throw new RuntimeException("Could not copy Docker image archive from {$host}:{$remotePath}: {$result->errorOutput()}");
        }
    }

    private function copyToHost(string $host, string $localPath, string $remotePath): void
    {
        if ($this->isLocalHost($host)) {
            $result = Process::timeout($this->timeoutSeconds)->run(sprintf(
                'cp %s %s',
                escapeshellarg($localPath),
                escapeshellarg($remotePath),
            ));
        } else {
            $result = Process::timeout($this->timeoutSeconds)->run(sprintf(
                'scp %s %s %s',
                $this->sshOptions(),
                escapeshellarg($localPath),
                $this->remotePath($host, $remotePath),
            ));
        }

        if (! $result->successful()) {
            throw new RuntimeException("Could not copy Docker image archive to {$host}:{$remotePath}: {$result->errorOutput()}");
        }
    }

    private function runOnHost(string $host, string $command, int $timeoutSeconds): ProcessResult
    {
        if ($this->isLocalHost($host)) {
            return Process::timeout($timeoutSeconds)->run($command);
        }

        return Process::timeout($timeoutSeconds)->run(sprintf(
            'ssh %s %s %s',
            $this->sshOptions(),
            escapeshellarg($host),
            escapeshellarg($command),
        ));
    }

    private function remotePath(string $host, string $path): string
    {
        return $this->remoteHost($host).':'.escapeshellarg($path);
    }

    private function remoteHost(string $host): string
    {
        return preg_match('/^[a-z0-9._-]+$/i', $host) === 1
            ? $host
            : escapeshellarg($host);
    }

    private function sshOptions(): string
    {
        return '-o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
    }

    private function sameHost(string $first, string $second): bool
    {
        return strtolower($first) === strtolower($second);
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['local', 'localhost', '127.0.0.1'], true);
    }
}
