<?php

declare(strict_types=1);

namespace App\Infrastructure\Releases;

use App\Domain\GitHub\GitHubRepository;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proves that the release workflow on `main` built the files of one published Orbit release
 * ([Release provenance](/reference/gateway-recovery#release-provenance)). It downloads the release's Sigstore bundle
 * without authentication and verifies it offline against the pinned trust root, for the exact bytes the caller
 * downloaded. Gateway release prepare and the CLI release catalog both use it.
 */
final readonly class ReleaseProvenance
{
    public const string Asset = 'provenance.sigstore.json';

    public const string Workflow = '.github/workflows/orbit-cli-release.yml';

    public const string Ref = 'refs/heads/main';

    public const string Issuer = 'https://token.actions.githubusercontent.com';

    private const int BundleMaxBytes = 262144;

    /**
     * @param  string  $repository  The repository that publishes the releases, `orbit.cli_releases.repository`.
     * @param  string  $trustRoot  The Sigstore `trusted_root.json`, `orbit.cli_releases.trusted_root`.
     */
    public function __construct(
        private string $repository,
        private string $trustRoot,
    ) {}

    /**
     * @param  array<string, string>  $files  The exact bytes of each release file the bundle must name.
     *
     * @throws ReleaseProvenanceRefused retryable only when the bundle cannot be downloaded
     */
    public function verify(string $tag, array $files): void
    {
        $repository = GitHubRepository::fromOrigin($this->repository);
        if (! $repository instanceof GitHubRepository) {
            throw new ReleaseProvenanceRefused('The release repository is not a github.com repository.');
        }
        $base = 'https://github.com/'.$repository->owner.'/'.$repository->name;
        $bundle = $this->download($base.'/releases/download/'.rawurlencode($tag).'/'.self::Asset);
        $trustRoot = @file_get_contents($this->trustRoot);
        if (! is_string($trustRoot)) {
            throw new ReleaseProvenanceRefused("The Sigstore trust root [{$this->trustRoot}] cannot be read.");
        }

        new SigstoreBundleVerifier($trustRoot)->verify(
            $bundle,
            $base.'/'.self::Workflow.'@'.self::Ref,
            self::Issuer,
            array_map(static fn (string $bytes): string => hash('sha256', $bytes), $files),
        );
    }

    private function download(string $url): string
    {
        try {
            $response = Http::timeout(30)->connectTimeout(10)->get($url);
        } catch (Throwable) {
            throw new ReleaseProvenanceRefused("The provenance bundle [{$url}] cannot be downloaded.", retryable: true);
        }
        if ($response->status() === 404) {
            throw new ReleaseProvenanceRefused("The release has no provenance bundle [{$url}].");
        }
        if (! $response->successful()) {
            throw new ReleaseProvenanceRefused("The provenance bundle [{$url}] cannot be downloaded.", retryable: true);
        }
        if (strlen($response->body()) > self::BundleMaxBytes) {
            throw new ReleaseProvenanceRefused('The provenance bundle is larger than '.self::BundleMaxBytes.' bytes.');
        }

        return $response->body();
    }
}
