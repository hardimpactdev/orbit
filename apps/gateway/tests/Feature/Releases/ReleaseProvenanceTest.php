<?php

declare(strict_types=1);

use App\Infrastructure\Releases\ReleaseProvenance;
use App\Infrastructure\Releases\ReleaseProvenanceRefused;
use App\Infrastructure\Releases\ReleaseProvenanceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\SigstoreTestSigner;

const PROVENANCE_URL = 'https://github.com/hardimpactdev/orbit/releases/download/cli-v0.4681.0/provenance.sigstore.json';

/** What the verification threw: `refused`, `download` or `local`, or null when it verified. */
function provenance_outcome(ReleaseProvenance $provenance, array $files = ['SHA256SUMS' => 'sums']): ?string
{
    try {
        $provenance->verify('cli-v0.4681.0', $files);
    } catch (ReleaseProvenanceRefused) {
        return 'refused';
    } catch (ReleaseProvenanceUnavailable $unavailable) {
        return $unavailable->local ? 'local' : 'download';
    }

    return null;
}

describe(ReleaseProvenance::class, function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        $this->provenance = new ReleaseProvenance(SigstoreTestSigner::Repository, SigstoreTestSigner::instance()->trustRootPath());
    });

    it('verifies the release bundle from a public download for the exact bytes', function (): void {
        Http::fake([PROVENANCE_URL => Http::response(SigstoreTestSigner::instance()->bundle(['SHA256SUMS' => 'sums']))]);

        expect(provenance_outcome($this->provenance))->toBeNull()
            ->and(provenance_outcome($this->provenance, ['SHA256SUMS' => 'other bytes']))->toBe('refused');
        Http::assertSent(static fn ($request): bool => ! $request->hasHeader('Authorization'));
    });

    it('expects the release workflow of the configured repository', function (): void {
        Http::fake(['https://github.com/acme/orbit/*' => Http::response(SigstoreTestSigner::instance()->bundle(['SHA256SUMS' => 'sums']))]);

        expect(fn () => new ReleaseProvenance('https://github.com/acme/orbit', SigstoreTestSigner::instance()->trustRootPath())->verify('cli-v0.4681.0', ['SHA256SUMS' => 'sums']))
            ->toThrow(ReleaseProvenanceRefused::class, 'https://github.com/acme/orbit/.github/workflows/orbit-cli-release.yml@refs/heads/main');
    });

    it('refuses a missing or oversized bundle for good, and waits when it cannot be downloaded', function (Closure $response, string $outcome): void {
        Http::fake([PROVENANCE_URL => $response]);

        expect(provenance_outcome($this->provenance))->toBe($outcome);
    })->with([
        'no bundle in the release' => [fn () => Http::response('Not Found', 404), 'refused'],
        'a bundle larger than the limit' => [fn () => Http::response(str_repeat(' ', 262145)), 'refused'],
        'a server error' => [fn () => Http::response('Bad Gateway', 502), 'download'],
        'a connection failure' => [fn () => throw new ConnectionException('Connection refused.'), 'download'],
    ]);

    it('reports a Gateway setting at fault, not the release', function (ReleaseProvenance $provenance): void {
        Http::fake([PROVENANCE_URL => Http::response(SigstoreTestSigner::instance()->bundle(['SHA256SUMS' => 'sums']))]);

        expect(provenance_outcome($provenance))->toBe('local');
    })->with([
        'a trust root path with a typo' => [fn () => new ReleaseProvenance(SigstoreTestSigner::Repository, '/nonexistent/trusted_root.json')],
        'a repository on another host' => [fn () => new ReleaseProvenance('https://gitlab.com/hardimpactdev/orbit', SigstoreTestSigner::instance()->trustRootPath())],
    ]);
});
