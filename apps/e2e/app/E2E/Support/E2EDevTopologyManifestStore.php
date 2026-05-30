<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

final readonly class E2EDevTopologyManifestStore
{
    public function __construct(
        private string $directory,
    ) {}

    public static function fromRepositoryRoot(string $repoRoot): self
    {
        return new self(rtrim($repoRoot, '/').'/apps/e2e/var/dev-topology');
    }

    public static function fromEnvironment(string $repoRoot): self
    {
        $directory = getenv('ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY');

        if (is_string($directory) && $directory !== '') {
            return new self($directory);
        }

        return self::fromRepositoryRoot($repoRoot);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function write(array $payload): void
    {
        $id = $this->payloadId($payload);

        $this->ensureDirectory();

        $written = file_put_contents(
            $this->path($id),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        if ($written === false) {
            throw new RuntimeException("Failed to write retained E2E topology manifest [{$id}].");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $id): ?array
    {
        $path = $this->path($id);

        if (! is_file($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $files = glob($this->directory.'/*.json');

        if ($files === false) {
            return [];
        }

        sort($files);

        $manifests = [];

        foreach ($files as $file) {
            $payload = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            if (is_array($payload)) {
                $manifests[] = $payload;
            }
        }

        return $manifests;
    }

    public function remove(string $id): void
    {
        $path = $this->path($id);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        mkdir($this->directory, 0777, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadId(array $payload): string
    {
        $id = $payload['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            throw new RuntimeException('A retained E2E topology manifest requires a non-empty id.');
        }

        return $id;
    }

    private function path(string $id): string
    {
        return $this->directory.'/'.$this->sanitize($id).'.json';
    }

    private function sanitize(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $id) ?? $id;
    }
}
