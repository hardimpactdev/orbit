<?php

declare(strict_types=1);

use App\Infrastructure\MachineSoftware\AuthenticatedUbuntuSnapshot;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Infrastructure\MachineSoftware\UbuntuReleaseSignature;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function snapshot_fixture_recipe(string $version = '1.2.3-1ubuntu1'): MachineRecipe
{
    return new MachineRecipe('sha256:test', '20261014T000000Z', ['amd64', 'arm64'], [
        'curl' => new ToolPin('ubuntu', $version, 'curl'),
    ], ['operator' => ['curl']], '{}');
}

function snapshot_fixture_probe(): AuthenticatedUbuntuSnapshot
{
    return new AuthenticatedUbuntuSnapshot(signatures: new UbuntuReleaseSignature(base_path('tests/Fixtures/machine-software/test-keyring.gpg')));
}

function fake_signed_snapshot(?Closure $override = null): void
{
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($override) {
        $url = $request->url();
        $replacement = $override === null ? null : $override($url);
        if ($replacement !== null) {
            return $replacement;
        }
        if (preg_match('~/dists/(resolute(?:-updates|-security)?)/InRelease$~', $url, $match)) {
            return Http::response(file_get_contents(base_path('tests/Fixtures/machine-software/'.$match[1].'.InRelease')));
        }
        if (str_ends_with($url, '/Packages.gz')) {
            return Http::response(file_get_contents(base_path('tests/Fixtures/machine-software/Packages.gz')));
        }

        throw new RuntimeException('Unexpected URL '.$url);
    });
}

describe('authenticated Ubuntu snapshot availability', function (): void {
    it('uses signed index digests and resolves the exact package across both architectures and three pockets', function (): void {
        fake_signed_snapshot();
        snapshot_fixture_probe()->verify(snapshot_fixture_recipe());
        Http::assertSentCount(18);
    });

    it('rejects an unavailable well-formed date with its release architecture and metadata URL', function (): void {
        Http::fake(['*' => Http::response('', 404)]);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'Snapshot [20261014T000000Z] release [26.04] architecture [amd64] metadata [https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/dists/resolute/InRelease]');
    });

    it('does not accept redirect or archive fallback as a verified date', function (): void {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://archive.ubuntu.com/ubuntu/dists/resolute/InRelease'])]);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'HTTP 302');
        Http::assertSentCount(1);
    });

    it('reports a network failure as failure to verify rather than accepting another source', function (): void {
        Http::fake(['*' => Http::failedConnection()]);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'Cannot verify [https://snapshot.ubuntu.com/');
        Http::assertSentCount(1);
    });

    it('rejects well-formed but unavailable exact Ubuntu versions', function (): void {
        fake_signed_snapshot();
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe('1.2.4-1ubuntu1')))
            ->toThrow(RuntimeException::class, 'Tool [curl] version [1.2.4-1ubuntu1] architecture [amd64] unavailable in snapshot [20261014T000000Z]');
    });

    it('rejects a package index whose bytes differ from signed metadata', function (): void {
        fake_signed_snapshot(fn (string $url) => str_ends_with($url, '/Packages.gz') ? Http::response('tampered') : null);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'Index digest/size mismatch');
    });

    it('retries transient gateway failures only at the same pinned URL', function (): void {
        Http::fake(['*' => Http::sequence()->push('', 502)->push('', 503)->push('', 504)]);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'HTTP 504');
        Http::assertSentCount(3);
        expect(Http::recorded()->map(fn ($pair) => $pair[0]->url())->unique()->values()->all())
            ->toBe(['https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/dists/resolute/InRelease']);
    });

    it('rejects an invalid InRelease signature', function (): void {
        fake_signed_snapshot(fn (string $url) => str_ends_with($url, '/InRelease') ? Http::response('unsigned metadata') : null);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'signature verification failed');
    });

    it('rejects a correctly signed release from the wrong pocket', function (): void {
        fake_signed_snapshot(fn (string $url) => str_contains($url, 'resolute-updates/InRelease')
            ? Http::response(file_get_contents(base_path('tests/Fixtures/machine-software/resolute.InRelease'))) : null);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'wrong release, pocket, or architecture');
    });
});
