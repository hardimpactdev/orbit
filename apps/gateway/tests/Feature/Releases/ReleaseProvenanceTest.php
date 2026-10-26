<?php

declare(strict_types=1);

use App\Infrastructure\Releases\ReleaseProvenance;
use App\Infrastructure\Releases\ReleaseProvenanceRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\SigstoreTestSigner;

const PROVENANCE_URL = 'https://github.com/hardimpactdev/orbit/releases/download/cli-v0.4681.0/provenance.sigstore.json';

function provenance_refusal(ReleaseProvenance $provenance, array $files = ['SHA256SUMS' => 'sums']): ?ReleaseProvenanceRefused
{
    try {
        $provenance->verify('cli-v0.4681.0', $files);
    } catch (ReleaseProvenanceRefused $refusal) {
        return $refusal;
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

        expect(provenance_refusal($this->provenance))->toBeNull()
            ->and(provenance_refusal($this->provenance, ['SHA256SUMS' => 'other bytes'])?->retryable)->toBeFalse();
        Http::assertSent(static fn ($request): bool => ! $request->hasHeader('Authorization'));
    });

    it('expects the release workflow of the configured repository', function (): void {
        Http::fake(['https://github.com/acme/orbit/*' => Http::response(SigstoreTestSigner::instance()->bundle(['SHA256SUMS' => 'sums']))]);

        $refusal = provenance_refusal(new ReleaseProvenance('https://github.com/acme/orbit', SigstoreTestSigner::instance()->trustRootPath()));

        expect($refusal?->getMessage())->toContain('https://github.com/acme/orbit/.github/workflows/orbit-cli-release.yml@refs/heads/main');
    });

    it('waits only when the bundle cannot be downloaded', function (Closure $response, bool $retryable): void {
        Http::fake([PROVENANCE_URL => $response]);

        expect(provenance_refusal($this->provenance)?->retryable)->toBe($retryable);
    })->with([
        'no bundle in the release' => [fn () => Http::response('Not Found', 404), false],
        'a bundle larger than the limit' => [fn () => Http::response(str_repeat(' ', 262145)), false],
        'a server error' => [fn () => Http::response('Bad Gateway', 502), true],
        'a connection failure' => [fn () => throw new ConnectionException('Connection refused.'), true],
    ]);

    it('refuses without a readable trust root or a GitHub repository', function (ReleaseProvenance $provenance): void {
        Http::fake([PROVENANCE_URL => Http::response(SigstoreTestSigner::instance()->bundle(['SHA256SUMS' => 'sums']))]);

        expect(provenance_refusal($provenance)?->retryable)->toBeFalse();
    })->with([
        'missing trust root' => [fn () => new ReleaseProvenance(SigstoreTestSigner::Repository, '/nonexistent/trusted_root.json')],
        'another host' => [fn () => new ReleaseProvenance('https://gitlab.com/hardimpactdev/orbit', SigstoreTestSigner::instance()->trustRootPath())],
    ]);
});
