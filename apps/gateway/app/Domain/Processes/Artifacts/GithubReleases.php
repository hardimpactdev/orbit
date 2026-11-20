<?php

declare(strict_types=1);

namespace App\Domain\Processes\Artifacts;

use App\Domain\Shared\ResourceOperationException;
use App\Models\ProcessArtifact;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads public GitHub releases for Process artifacts (docs/reference/processes-and-schedules.md#upgrade-a-process).
 * The Gateway resolves a version here; the Node only downloads the URL and checks the SHA-256 it is given.
 */
final readonly class GithubReleases
{
    public const string RepositoryPattern = '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/D';

    public const string VersionPattern = '/\A[0-9A-Za-z][0-9A-Za-z._+-]{0,63}\z/D';

    private const string Api = 'https://api.github.com';

    private const int ChecksumsMaxBytes = 262144;

    private const int JsonMaxBytes = 4 * 1024 * 1024;

    /** A version without its leading `v`, or null when it is no version. */
    public static function normalize(string $version): ?string
    {
        $version = preg_replace('/\A[vV](?=[0-9])/', '', trim($version)) ?? '';

        return preg_match(self::VersionPattern, $version) === 1 && ! str_contains($version, '..') ? $version : null;
    }

    public function resolve(ProcessArtifact $artifact, string $version): ResolvedRelease
    {
        $unknown = static fn (string $why): ResourceOperationException => new ResourceOperationException('process.version_unknown',
            "Release {$version} of {$artifact->repository} cannot be installed: {$why}.", 422, details: ['repository' => $artifact->repository, 'version' => $version]);
        $release = null;
        $tag = null;
        foreach (['v'.$version, $version] as $candidate) {
            $response = $this->get(self::Api.'/repos/'.$artifact->repository.'/releases/tags/'.rawurlencode($candidate), 'application/vnd.github+json');
            if ($response?->status() === 404) {
                continue;
            }
            $release = $this->json($response);
            if ($release === null) {
                throw $unknown('GitHub did not answer');
            }
            $tag = $candidate;
            break;
        }
        if ($release === null || $tag === null || ($release['draft'] ?? true) !== false) {
            throw $unknown('no published release has the tag v'.$version.' or '.$version);
        }
        $name = str_replace('{version}', $version, $artifact->asset);
        $prefix = 'https://github.com/'.$artifact->repository.'/releases/download/'.$tag.'/';
        $rows = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $assets = array_values(array_filter($rows,
            static fn (mixed $row): bool => is_array($row) && ($row['name'] ?? null) === $name));
        if (count($assets) !== 1) {
            throw $unknown("the release has no single asset named {$name}");
        }
        $url = $assets[0]['browser_download_url'] ?? null;
        if (! is_string($url) || strcasecmp($url, $prefix.rawurlencode($name)) !== 0 && strcasecmp($url, $prefix.$name) !== 0) {
            throw $unknown("the asset {$name} is not served from the release");
        }
        $sha256 = $artifact->checksums === null
            ? $this->digest($assets[0]['digest'] ?? null)
            : $this->checksum($rows, $prefix, $artifact->checksums, $name);
        if ($sha256 === null) {
            throw $unknown("no SHA-256 is published for {$name}");
        }

        return new ResolvedRelease($version, $tag, $name, $url, $sha256);
    }

    /** The latest published, non-prerelease version of a repository, without its leading `v`; null when GitHub does not answer. */
    public function latest(string $repository): ?string
    {
        $release = $this->json($this->get(self::Api.'/repos/'.$repository.'/releases/latest', 'application/vnd.github+json'));
        if ($release === null || ($release['draft'] ?? true) !== false || ($release['prerelease'] ?? true) !== false || ! is_string($release['tag_name'] ?? null)) {
            return null;
        }

        return self::normalize($release['tag_name']);
    }

    private function digest(mixed $digest): ?string
    {
        return is_string($digest) && preg_match('/\Asha256:([0-9a-f]{64})\z/D', strtolower($digest), $match) === 1 ? $match[1] : null;
    }

    /** @param array<array-key,mixed> $assets */
    private function checksum(array $assets, string $prefix, string $checksums, string $name): ?string
    {
        $rows = array_values(array_filter($assets, static fn (mixed $row): bool => is_array($row) && ($row['name'] ?? null) === $checksums));
        $url = $rows[0]['browser_download_url'] ?? null;
        if (count($rows) !== 1 || ! is_string($url) || ! str_starts_with(strtolower($url), strtolower($prefix))) {
            return null;
        }
        $response = $this->get($url, 'application/octet-stream');
        if ($response === null || ! $response->successful() || strlen($response->body()) > self::ChecksumsMaxBytes) {
            return null;
        }
        $found = null;
        foreach (preg_split('/\r?\n/', $response->body()) ?: [] as $line) {
            if (preg_match('/\A([0-9a-fA-F]{64}) [ *]?(\S+)\z/D', trim($line), $match) === 1 && $match[2] === $name) {
                if ($found !== null) {
                    return null;
                }
                $found = strtolower($match[1]);
            }
        }

        return $found;
    }

    /** @return array<array-key,mixed>|null */
    private function json(?Response $response): ?array
    {
        if ($response === null || ! $response->successful() || strlen($response->body()) > self::JsonMaxBytes) {
            return null;
        }
        $data = json_decode($response->body(), true);

        return is_array($data) ? $data : null;
    }

    private function get(string $url, string $accept): ?Response
    {
        try {
            return Http::timeout(15)->connectTimeout(5)->withHeaders(['Accept' => $accept, 'X-GitHub-Api-Version' => '2022-11-28'])->get($url);
        } catch (Throwable) {
            return null;
        }
    }
}
