<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Proof\ProofCommitSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Proof\PublishedProofObserverRelease;
use App\Infrastructure\Releases\ReleaseProvenance;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function publishedObserverFixture(string $name): string
{
    return file_get_contents(base_path('tests/Fixtures/published-agent-release/'.$name));
}

function admitObserverPin(string $pin): string
{
    $source = Mockery::mock(ProofCommitSource::class);
    $source->shouldReceive('file')->once()->with(str_repeat('a', 40), GatewayReleaseAgentDelivery::Pin)->andReturn($pin);
    $delivery = new GatewayReleaseAgentDelivery(app(ProcessRunner::class),
        new ReleaseProvenance('https://github.com/hardimpactdev/orbit', resource_path('sigstore/trusted_root.json')),
        'https://github.com/hardimpactdev/orbit', sys_get_temp_dir());

    return new PublishedProofObserverRelease($delivery, $source)->forCommit(str_repeat('a', 40));
}

beforeEach(function (): void {
    $base = 'https://github.com/hardimpactdev/orbit/releases/download/cli-v0.10003.0/';
    Http::preventStrayRequests();
    Http::fake([
        $base.'manifest.json' => Http::response(publishedObserverFixture('manifest.json')),
        $base.'SHA256SUMS' => Http::response(publishedObserverFixture('SHA256SUMS')),
        $base.'provenance.sigstore.json' => Http::response(publishedObserverFixture('provenance.sigstore.json')),
    ]);
});

it('admits a byte-equal published pin with real verified provenance on the trusted Gateway', function (): void {
    $pin = publishedObserverFixture('manifest.json');
    expect(admitObserverPin($pin))->toBe($pin);
    Http::assertSentCount(3);
});

it('refuses a candidate controlled URL even with a matching binary digest before any download', function (): void {
    $pin = json_decode(publishedObserverFixture('manifest.json'), true);
    $pin['artifacts']['linux-x86_64']['url'] = 'https://attacker.example/releases/download/cli-v0.10003.0/orbit-agent-linux-x86_64';
    expect(fn () => admitObserverPin(json_encode($pin)))->toThrow(GatewayReleaseException::class, 'no fixed published release');
    Http::assertNothingSent();
});

it('refuses a semantically equal pin whose published bytes differ', function (): void {
    expect(fn () => admitObserverPin(publishedObserverFixture('manifest.json')."\n"))
        ->toThrow(GatewayReleaseException::class, 'published manifest differs');
});

it('refuses missing or invalid provenance before admitting any delivery', function (int $status, string $body): void {
    Http::swap(new Factory);
    $base = 'https://github.com/hardimpactdev/orbit/releases/download/cli-v0.10003.0/';
    Http::fake([
        $base.'manifest.json' => Http::response(publishedObserverFixture('manifest.json')),
        $base.'SHA256SUMS' => Http::response(publishedObserverFixture('SHA256SUMS')),
        $base.'provenance.sigstore.json' => Http::response($body, $status),
    ]);
    expect(fn () => admitObserverPin(publishedObserverFixture('manifest.json')))
        ->toThrow(GatewayReleaseException::class, 'provenance does not verify');
})->with([[404, ''], [200, '{}']]);
