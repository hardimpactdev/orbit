<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Releases\ReleaseProvenance;
use App\Infrastructure\Releases\ReleaseProvenanceRefused;
use App\Infrastructure\Releases\ReleaseProvenanceUnavailable;
use App\Models\Node;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** The release commit's pinned, provenance-verified observer delivery, independent of Gateway release publication. */
final readonly class GatewayReleaseAgentDelivery implements GatewayReleaseAgent
{
    public const string Manifest = 'apps/agent/delivery/manifest.json';

    /** The pin in each commit: a byte copy of the published release manifest the commit's code was tested with. */
    public const string Pin = 'apps/gateway/resources/agent/frozen-manifest.json';

    /** @var list<string> */
    public const array Platforms = ['linux-x86_64', 'macos-aarch64', 'macos-x86_64'];

    private const int MetadataMaxBytes = 65536;

    /**
     * @param  string  $repository  The public repository that publishes the releases, `orbit.cli_releases.repository`.
     * @param  string  $cacheDirectory  Where verified agent binaries wait, `ORBIT_HOME/agent-artifacts`, as {@see AgentDelivery} keeps them.
     */
    public function __construct(
        private ProcessRunner $processes,
        private ReleaseProvenance $provenance,
        private string $repository,
        private string $cacheDirectory,
    ) {}

    /**
     * Verifies the release commit's own pinned delivery before anything changes, so a commit that moves the pin
     * ships its agent in its own release. Gateway commits need no published agent release.
     */
    public function fetch(string $revision, Node $gateway): array
    {
        $sha = $this->commit($revision);
        $frozen = $this->download($this->pinUrl($sha), $sha);
        $manifest = $this->verifyPin($frozen, $sha);

        // The binary goes into the shared artifact cache, where the install below and every Node install find it.
        $staged = $this->cacheDirectory.'/manifest-'.bin2hex(random_bytes(8)).'.json';
        try {
            if ((! is_dir($this->cacheDirectory) && ! @mkdir($this->cacheDirectory, 0o700, true) && ! is_dir($this->cacheDirectory))
                || @file_put_contents($staged, $manifest) === false) {
                throw $this->unavailable($sha, 'The agent artifact cache cannot be written.');
            }
            new AgentDelivery($staged, $this->cacheDirectory)->localFile($gateway);
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable($sha, 'The release agent for the Gateway cannot be downloaded: '.$exception->getMessage());
        } finally {
            @unlink($staged);
        }

        return ['sha' => $sha, 'manifest' => $manifest];
    }

    /** The same release admission for proof pins; no candidate code is executed. */
    public function verifyPin(string $frozen, string $sha): string
    {
        if (strlen($frozen) > self::MetadataMaxBytes) {
            throw $this->invalid($sha, 'The pinned agent manifest is too large.');
        }
        $pin = json_decode($frozen, true);
        $url = data_get($pin, 'artifacts.linux-x86_64.url');
        if (! is_string($url) || preg_match('#\A'.preg_quote(rtrim($this->repository, '/'), '#').'/releases/download/(cli-v0\.[1-9][0-9]*\.0)/orbit-agent-linux-x86_64\z#D', $url, $match) !== 1) {
            throw $this->invalid($sha, 'The pinned agent manifest has no fixed published release.');
        }
        $tag = $match[1];
        $base = rtrim($this->repository, '/').'/releases/download/'.rawurlencode($tag);
        $manifest = $this->download($base.'/manifest.json', $sha);
        $sums = $this->download($base.'/SHA256SUMS', $sha);
        try {
            $this->provenance->verify($tag, ['manifest.json' => $manifest, 'SHA256SUMS' => $sums]);
        } catch (ReleaseProvenanceRefused $refusal) {
            throw new GatewayReleaseException('agent-delivery', 'gateway.release_provenance_invalid', 'The release provenance does not verify: '.$refusal->getMessage(), 422, sha: $sha, previous: $refusal);
        } catch (ReleaseProvenanceUnavailable $unavailable) {
            throw $unavailable->local
                ? new GatewayReleaseException('agent-delivery', 'gateway.release_provenance_unconfigured', 'The Gateway cannot verify release provenance: '.$unavailable->getMessage(), 503, sha: $sha, previous: $unavailable)
                : $this->unavailable($sha, $unavailable->getMessage());
        }
        if (! hash_equals(hash('sha256', $frozen), hash('sha256', $manifest))) {
            throw $this->invalid($sha, 'The published manifest differs from the commit\'s agent pin.');
        }
        $this->validate($manifest, $this->checksums($sums, $sha), $base, $sha);

        return $manifest;
    }

    /** Resolve the Gateway commit independently of the frozen agent's published release. */
    private function commit(string $revision): string
    {
        $revision = GatewayReleaseCommit::parse($revision);
        if (! str_starts_with($this->repository, 'https://')) {
            throw $this->unavailable(null, 'The release repository must be an HTTPS URL.');
        }
        if (GatewayReleaseCommit::isSha($revision)) {
            return $revision;
        }
        $result = $this->processes->run(new ProcessInvocation(
            ['git', 'ls-remote', '--refs', $this->repository, 'refs/tags/cli-v*', 'refs/heads/*'],
            timeout: 60.0,
            environment: ['GIT_TERMINAL_PROMPT' => '0'],
        ));
        if (! $result->succeeded()) {
            throw $this->unavailable(null, 'The release tags of the repository cannot be listed.');
        }

        $matches = [];
        foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $line) {
            if (preg_match('#\A([0-9a-f]{40})\trefs/(?:tags/cli-v0\.[1-9][0-9]*\.0|heads/[^\s]+)\z#D', $line, $tag) === 1 && str_starts_with($tag[1], $revision)) {
                $matches[$tag[1]] = true;
            }
        }
        if (count($matches) > 1) {
            throw new GatewayReleaseException('resolve', 'gateway.release_commit_unknown', "The prefix [{$revision}] names more than one commit.", 422);
        }
        if ($matches === []) {
            throw new GatewayReleaseException('resolve', 'gateway.release_commit_unknown', "No published tag or branch resolves [{$revision}]. Pass its full commit SHA.", 422);
        }

        return (string) array_key_first($matches);
    }

    /** GitHub serves a commit's file from its raw host; the github.com/raw redirect can answer 504 for new commits for a long time. */
    private function pinUrl(string $sha): string
    {
        $repository = rtrim($this->repository, '/');

        return str_starts_with($repository, 'https://github.com/')
            ? 'https://raw.githubusercontent.com/'.substr($repository, strlen('https://github.com/')).'/'.$sha.'/'.self::Pin
            : $repository.'/raw/'.$sha.'/'.self::Pin;
    }

    private function download(string $url, string $sha): string
    {
        try {
            $response = Http::timeout(30)->connectTimeout(10)->get($url);
        } catch (Throwable) {
            throw $this->unavailable($sha, "The release asset [{$url}] cannot be downloaded.");
        }
        $body = $response->body();
        if (! $response->successful() || strlen($body) > self::MetadataMaxBytes) {
            throw $this->unavailable($sha, "The release asset [{$url}] cannot be downloaded.");
        }

        return $body;
    }

    /** @return array<string, string> SHA-256 by asset name */
    private function checksums(string $body, string $sha): array
    {
        $checksums = [];
        foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
            if (preg_match('/\A([0-9a-f]{64}) [ *]([A-Za-z0-9._-]+)\z/D', $line, $match) !== 1 || isset($checksums[$match[2]])) {
                throw $this->invalid($sha, 'The release SHA256SUMS is malformed.');
            }
            $checksums[$match[2]] = $match[1];
        }

        return $checksums;
    }

    /** @param array<string, string> $checksums */
    private function validate(string $body, array $checksums, string $base, string $sha): void
    {
        try {
            $manifest = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid($sha, 'The release agent manifest is not JSON.');
        }
        $artifacts = is_array($manifest) ? ($manifest['artifacts'] ?? null) : null;
        if (! is_array($manifest) || ! is_string($manifest['commit'] ?? null) || ! GatewayReleaseCommit::isSha($manifest['commit'])
            || ! is_string($manifest['version'] ?? null) || $manifest['version'] === '' || ! is_array($artifacts)) {
            throw $this->invalid($sha, 'The release agent manifest has no commit, version, or artifacts.');
        }
        $keys = array_keys($artifacts);
        sort($keys);
        if ($keys !== self::Platforms) {
            throw $this->invalid($sha, 'The release agent manifest does not name exactly the delivered platforms.');
        }
        foreach ($artifacts as $platform => $artifact) {
            $name = 'orbit-agent-'.$platform;
            if (! is_array($artifact) || ($artifact['path'] ?? null) !== $name || ($artifact['url'] ?? null) !== $base.'/'.$name
                || ! isset($checksums[$name]) || ($artifact['sha256'] ?? null) !== $checksums[$name]) {
                throw $this->invalid($sha, "The release agent [{$platform}] does not match the release and its SHA256SUMS.");
            }
        }
    }

    /**
     * Writes the manifest once; a sealed release is opened for that one file and sealed again. A `delivery`
     * directory that an interrupted attempt left without a manifest is used again.
     */
    private function unavailable(?string $sha, string $message): GatewayReleaseException
    {
        return new GatewayReleaseException('agent-delivery', 'gateway.release_agent_delivery_unavailable', $message.' The frozen published agent must be reachable.', 503, sha: $sha);
    }

    private function invalid(string $sha, string $message): GatewayReleaseException
    {
        return new GatewayReleaseException('agent-delivery', 'gateway.release_agent_delivery_invalid', $message, 500, sha: $sha);
    }
}
