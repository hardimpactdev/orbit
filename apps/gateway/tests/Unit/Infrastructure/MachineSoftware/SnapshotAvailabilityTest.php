<?php

declare(strict_types=1);

use App\Infrastructure\MachineSoftware\AuthenticatedUbuntuSnapshot;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Infrastructure\MachineSoftware\UbuntuReleaseSignature;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => Sleep::fake());

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
    Http::fake(function ($request, array $options) use ($override) {
        $url = $request->url();
        $replacement = $override === null ? null : $override($url, $options);
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
    it('allows slow metadata transfers while bounding connection time stalls and total time', function (): void {
        fake_signed_snapshot(function (string $url, array $options) {
            expect($options['connect_timeout'])->toBe(15)
                ->and($options['timeout'])->toBe(120)
                ->and($options['curl'][CURLOPT_LOW_SPEED_LIMIT])->toBe(1)
                ->and($options['curl'][CURLOPT_LOW_SPEED_TIME])->toBe(15)
                ->and($options['allow_redirects'])->toBeFalse();

            return null;
        });
        snapshot_fixture_probe()->verify(snapshot_fixture_recipe());
        Http::assertSentCount(18);
    });

    it('uses signed index digests and resolves the exact package across both architectures and three pockets', function (): void {
        fake_signed_snapshot();
        snapshot_fixture_probe()->verify(snapshot_fixture_recipe());
        Http::assertSentCount(18);
    });

    it('rejects an unavailable well-formed date with its release architecture and metadata URL', function (): void {
        Http::fake(['*' => Http::response('', 404)]);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'Snapshot [20261014T000000Z] release [26.04] architecture [amd64] metadata [https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/dists/resolute/InRelease]');
        Http::assertSentCount(1);
        Sleep::assertSleptTimes(0);
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
        Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(20)->seconds(), Sleep::for(30)->seconds()]);
    });

    it('rejects well-formed but unavailable exact Ubuntu versions', function (): void {
        fake_signed_snapshot();
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe('1.2.4-1ubuntu1')))
            ->toThrow(RuntimeException::class, 'Tool [curl] version [1.2.4-1ubuntu1] architectures [amd64, arm64] unavailable in snapshot [20261014T000000Z]');
    });

    it('rejects a package index whose bytes differ from signed metadata', function (): void {
        fake_signed_snapshot(fn (string $url) => str_ends_with($url, '/Packages.gz') ? Http::response('tampered') : null);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'Index digest/size mismatch');
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(0);
    });

    it('retries transient gateway failures only at the same pinned URL', function (): void {
        Http::fake(['*' => Http::sequence()->push('', 502)->push('', 503)->push('', 501)->push('', 504)]);
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, 'HTTP 504');
        Http::assertSentCount(4);
        Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(20)->seconds(), Sleep::for(30)->seconds()]);
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

it('resolves the newest signed versions for a snapshot bump independently of stale pins', function (): void {
    fake_signed_snapshot();
    expect(snapshot_fixture_probe()->latest('20261014T000000Z', ['amd64', 'arm64'], ['curl']))
        ->toBe(['curl' => '1.2.3-1ubuntu1']);
});

it('refuses stale Ubuntu pins even when that version has a valid syntax', function (): void {
    fake_signed_snapshot();
    expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe('1.2.2-1ubuntu1')))
        ->toThrow(RuntimeException::class, 'Bump the snapshot and all Ubuntu pins together.');
});

it('selects Debian version maxima across signed release updates and security indexes', function (): void {
    $fixtures = base_path('tests/Fixtures/machine-software/snapshot-versions');
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($fixtures) {
        preg_match('~/dists/(resolute(?:-updates|-security)?)/(.+)$~', $request->url(), $match);

        return Http::response(file_get_contents($fixtures.'/'.$match[1].($match[2] === 'InRelease' ? '.InRelease' : '.gz')));
    });
    $resolver = new AuthenticatedUbuntuSnapshot(signatures: new UbuntuReleaseSignature($fixtures.'/keyring.gpg'));

    expect($resolver->latest('20261014T000000Z', ['amd64', 'arm64'], ['curl']))
        ->toBe(['curl' => '1.2.10-1ubuntu1']);
    expect(fn () => $resolver->verify(snapshot_fixture_recipe('1.2.9-1ubuntu1')))
        ->toThrow(RuntimeException::class, 'Bump the snapshot and all Ubuntu pins together.');
});

it('recovers from a 503 and a connection reset before validating signed metadata', function (bool $reset, string $suffix): void {
    $failed = false;
    fake_signed_snapshot(function (string $url) use (&$failed, $reset, $suffix) {
        if (! $failed && str_ends_with($url, $suffix)) {
            $failed = true;

            return $reset ? Http::failedConnection() : Http::response('', 503);
        }

        return null;
    });
    snapshot_fixture_probe()->verify(snapshot_fixture_recipe());
    Sleep::assertSequence([Sleep::for(10)->seconds()]);
})->with([[false, 'InRelease'], [true, 'InRelease'], [false, 'Packages.gz'], [true, 'Packages.gz']]);

it('reuses immutable metadata without network access and still rejects cached tampering', function (string $kind): void {
    $directory = sys_get_temp_dir().'/snapshot-cache-'.bin2hex(random_bytes(6));
    config(['orbit.recipe_snapshot_cache' => $directory]);
    try {
        fake_signed_snapshot();
        snapshot_fixture_probe()->verify(snapshot_fixture_recipe());
        Http::assertSentCount(18);
        Http::fake(['*' => Http::response('', 503)]);
        snapshot_fixture_probe()->verify(snapshot_fixture_recipe());
        Http::assertNothingSent();

        $url = 'https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/dists/resolute/'
            .($kind === 'signature' ? 'InRelease' : 'main/binary-amd64/Packages.gz');
        File::put($directory.'/20261014T000000Z/amd64/'.hash('sha256', $url), 'tampered');
        expect(fn () => snapshot_fixture_probe()->verify(snapshot_fixture_recipe()))
            ->toThrow(RuntimeException::class, $kind === 'signature' ? 'signature verification failed' : 'Index digest/size mismatch');
        Http::assertNothingSent();
        Sleep::assertSleptTimes(0);
    } finally {
        File::deleteDirectory($directory);
    }
})->with(['signature', 'digest']);
