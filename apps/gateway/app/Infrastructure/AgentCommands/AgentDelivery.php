<?php

declare(strict_types=1);

namespace App\Infrastructure\AgentCommands;

use App\Models\Node;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * The agent build this Gateway release delivers, read from its delivery manifest (ADR 0225).
 *
 * CI writes the manifest next to the tested artifacts: the source commit, the Cargo version, and for each
 * platform the SHA-256 and either a file beside the manifest or a release URL. A release URL is downloaded
 * once into `ORBIT_HOME/agent-artifacts/<sha256>` and checked before use.
 */
final class AgentDelivery
{
    private ?string $commit = null;

    private ?string $version = null;

    /** @var array<string, array{sha256: string, path?: string, url?: string}>|null */
    private ?array $artifacts = null;

    public function __construct(
        private readonly string $manifestPath,
        private readonly string $cacheDirectory,
    ) {}

    /** The artifact key of a Node, such as `linux-x86_64`. */
    public static function key(Node|AgentGuest $node): string
    {
        $architecture = match ($node->architecture) {
            'arm64', 'aarch64' => 'aarch64',
            'amd64', 'x86_64' => 'x86_64',
            default => (string) $node->architecture,
        };

        return "{$node->platform}-{$architecture}";
    }

    public function commit(): string
    {
        $this->load();

        return (string) $this->commit;
    }

    /** The Cargo version of the delivered build. */
    public function version(): string
    {
        $this->load();

        return (string) $this->version;
    }

    /** @return array<string, array{sha256: string, path?: string, url?: string}> */
    public function artifacts(): array
    {
        $this->load();

        return $this->artifacts ?? [];
    }

    /**
     * The version a Node's observer reports in presence for this pin: the Cargo version with the first 16
     * hexadecimal digits of the binary's SHA-256 as build metadata.
     */
    public function reportedVersion(Node $node): string
    {
        return $this->version().'+'.substr($this->pin($node), 0, 16);
    }

    /** The pinned SHA-256 for a Node. */
    public function pin(Node|AgentGuest $node): string
    {
        return $this->artifact(self::key($node))['sha256'];
    }

    /** A verified local copy of the pinned binary for a Node. */
    public function localFile(Node|AgentGuest $node): string
    {
        $key = self::key($node);
        $artifact = $this->artifact($key);
        $path = $artifact['path'] ?? null;
        $file = $path !== null
            ? (str_starts_with($path, '/') ? $path : dirname($this->manifestPath).'/'.$path)
            : $this->download($key, $artifact['url'] ?? '', $artifact['sha256']);

        if (@hash_file('sha256', $file) !== $artifact['sha256']) {
            throw new RuntimeException("The delivered agent for [{$key}] does not match its pin.");
        }

        return $file;
    }

    /** @return array{sha256: string, path?: string, url?: string} */
    private function artifact(string $key): array
    {
        return $this->artifacts()[$key] ?? throw new RuntimeException("The agent delivery has no build for [{$key}].");
    }

    private function download(string $key, string $url, string $sha256): string
    {
        $target = "{$this->cacheDirectory}/{$sha256}";
        if (is_file($target) && hash_file('sha256', $target) === $sha256) {
            return $target;
        }
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException("The agent delivery has no file or HTTPS URL for [{$key}].");
        }
        if (! is_dir($this->cacheDirectory) && ! @mkdir($this->cacheDirectory, 0o700, true) && ! is_dir($this->cacheDirectory)) {
            throw new RuntimeException('The agent artifact cache cannot be created.');
        }

        $partial = "{$target}.".bin2hex(random_bytes(6)).'.partial';
        $response = Http::timeout(120)->connectTimeout(20)->sink($partial)->get($url);
        if (! $response->successful() || hash_file('sha256', $partial) !== $sha256) {
            @unlink($partial);

            throw new RuntimeException("The agent download for [{$key}] failed or does not match its pin.");
        }
        rename($partial, $target);

        return $target;
    }

    private function load(): void
    {
        if ($this->artifacts !== null) {
            return;
        }

        $contents = @file_get_contents($this->manifestPath);
        if ($contents === false) {
            throw new RuntimeException("The agent delivery manifest [{$this->manifestPath}] is missing.");
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The agent delivery manifest is not JSON.', previous: $exception);
        }

        if (! is_array($manifest) || ! is_string($manifest['commit'] ?? null) || ! is_string($manifest['version'] ?? null) || ! is_array($manifest['artifacts'] ?? null)) {
            throw new RuntimeException('The agent delivery manifest has no commit, version, or artifacts.');
        }

        $artifacts = [];
        foreach ($manifest['artifacts'] as $key => $artifact) {
            $sha256 = is_array($artifact) ? ($artifact['sha256'] ?? null) : null;
            if (! is_string($key) || ! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/D', $sha256) !== 1) {
                throw new RuntimeException('The agent delivery manifest has an invalid artifact.');
            }
            $entry = ['sha256' => $sha256];
            if (is_string($artifact['path'] ?? null)) {
                $entry['path'] = $artifact['path'];
            }
            if (is_string($artifact['url'] ?? null)) {
                $entry['url'] = $artifact['url'];
            }
            $artifacts[$key] = $entry;
        }

        $this->commit = $manifest['commit'];
        $this->version = $manifest['version'];
        $this->artifacts = $artifacts;
    }
}
