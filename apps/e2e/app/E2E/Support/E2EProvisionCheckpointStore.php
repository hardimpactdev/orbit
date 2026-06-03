<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

final readonly class E2EProvisionCheckpointStore
{
    public function __construct(
        private IncusHost $host,
        private int $writeAttempts = 3,
        private int $writeRetryDelayMilliseconds = 250,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function read(E2ETopologyKind $kind): ?array
    {
        $contents = $this->host->readTextFile($this->path($kind));

        if ($contents === null || trim($contents) === '') {
            return null;
        }

        $manifest = json_decode($contents, associative: true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function write(E2ETopologyKind $kind, array $manifest): void
    {
        $path = $this->path($kind);
        $contents = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lastErrorOutput = '';

        for ($attempt = 1; $attempt <= max(1, $this->writeAttempts); $attempt++) {
            $result = $this->host->writeTextFile($path, $contents);

            if ($result->successful()) {
                return;
            }

            $lastErrorOutput = $result->errorOutput();

            if ($attempt < $this->writeAttempts && $this->writeRetryDelayMilliseconds > 0) {
                usleep($this->writeRetryDelayMilliseconds * 1000);
            }
        }

        throw new RuntimeException("Could not write provision checkpoint manifest [{$path}]: {$lastErrorOutput}");
    }

    private function path(E2ETopologyKind $kind): string
    {
        return implode('/', [
            '.cache',
            'orbit-e2e',
            'provision-checkpoints',
            E2ETopologyArtifactNamespace::artifactSet(),
            "{$kind->value}.json",
        ]);
    }
}
