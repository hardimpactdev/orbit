<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Sleep::fake();
    Http::preventStrayRequests();
    app()->instance(ProcessRunner::class, new class implements ProcessRunner
    {
        public function run(ProcessInvocation $invocation): CommandResult
        {
            throw new RuntimeException('Release verification must use Laravel HTTP instead of spawning curl.');
        }
    });
});

/** @param list<int> $backoff */
function release_verifier(array $backoff = []): HttpGatewayReleaseVerifier
{
    return app()->make(HttpGatewayReleaseVerifier::class, ['origin' => 'https://gateway.test', 'caFile' => '/no/such/ca.pem', 'backoff' => $backoff]);
}

describe('release version verification', function (): void {
    it('accepts only the full commit or its 12-digit release id', function (string $version): void {
        Http::fake([
            'https://gateway.test/up' => Http::response('ok'),
            'https://gateway.test/api/v1/gateway/status' => Http::response(['data' => ['status' => 'ok', 'version' => $version]]),
        ]);

        expect(release_verifier()->verify(str_repeat('a', 40)))->toBe(['status' => 'ok', 'version' => $version]);
    })->with([str_repeat('a', 40), str_repeat('a', 12), str_repeat('A', 40), ' '.str_repeat('a', 12).' ']);

    it('refuses other versions and preserves the verification failure', function (string $version): void {
        $sha = '1'.str_repeat('a', 39);
        Http::fake([
            'https://gateway.test/up' => Http::response('ok'),
            'https://gateway.test/api/v1/gateway/status' => Http::response(['data' => ['status' => 'ok', 'version' => $version]]),
        ]);

        expect(fn () => release_verifier()->verify($sha))->toThrow(function (GatewayReleaseException $exception) use ($version, $sha): void {
            expect($exception->step)->toBe('verify');
            expect($exception->errorCode)->toBe('gateway.release_verify_failed');
            expect($exception->getMessage())->toBe("Gateway status is [ok] at version [{$version}], not commit [{$sha}].");
        });
    })->with(['dev', '1', '1aaaaaa', '1aaaaaaaaaaaa']);

    it('retries the complete check after a stale version or a status HTTP failure', function (): void {
        $sha = str_repeat('a', 40);
        Http::fake([
            'https://gateway.test/up' => Http::response('ok'),
            'https://gateway.test/api/v1/gateway/status' => Http::sequence()
                ->push(['data' => ['status' => 'ok', 'version' => 'dev']])
                ->push('busy', 503)
                ->push(['data' => ['status' => 'ok', 'version' => $sha]]),
        ]);

        expect(release_verifier([2, 4, 8])->verify($sha)['status'])->toBe('ok');

        expect(Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all())->toBe([
            'https://gateway.test/up', 'https://gateway.test/api/v1/gateway/status',
            'https://gateway.test/up', 'https://gateway.test/api/v1/gateway/status',
            'https://gateway.test/up', 'https://gateway.test/api/v1/gateway/status',
        ]);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
    });
});

describe('endpoint and serving checks', function (): void {
    it('retries a connection failure and an HTTP failure at up with the existing backoff', function (): void {
        Http::fake([
            'https://gateway.test/up' => Http::sequence()->pushFailedConnection('Operation timed out')->push('busy', 503)->push('ok'),
            'https://gateway.test/api/v1/gateway/status' => Http::response(['status' => 'ok', 'version' => 'dev']),
        ]);

        expect(release_verifier([2, 4, 8])->serving())->toBe(['status' => 'ok', 'version' => 'dev']);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
    });

    it('reports the failed endpoint and detail after every retry is exhausted', function (string $endpoint, bool $connectionFailure): void {
        Http::fake([
            'https://gateway.test/up' => Http::response('ok'),
            'https://gateway.test/api/v1/gateway/status' => Http::response(['status' => 'ok', 'version' => 'dev']),
            'https://gateway.test/'.$endpoint => $connectionFailure ? Http::failedConnection('Operation timed out') : Http::response('private response body', 503),
        ]);
        $message = $endpoint === 'up' ? 'Gateway /up did not succeed.' : 'Gateway status did not succeed.';

        expect(fn () => release_verifier([2, 4])->serving())->toThrow(function (GatewayReleaseException $exception) use ($message, $connectionFailure): void {
            expect($exception->step)->toBe('verify');
            expect($exception->errorCode)->toBe('gateway.release_verify_failed');
            expect($exception->getMessage())->toStartWith($message)->toContain($connectionFailure ? 'Operation timed out' : '503')->not->toContain('private response body');
        });
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
    })->with([['up', true], ['up', false], ['api/v1/gateway/status', true], ['api/v1/gateway/status', false]]);

    it('keeps a partial HTTP error body out of the failure detail', function (string $endpoint): void {
        Http::fake([
            'https://gateway.test/up' => Http::response('ok'),
            'https://gateway.test/api/v1/gateway/status' => Http::response(['status' => 'ok', 'version' => 'dev']),
            'https://gateway.test/'.$endpoint => static fn () => throw new RequestException(new Response(new PsrResponse(503, [], 'private partial response body'))),
        ]);

        expect(fn () => release_verifier()->serving())->toThrow(function (GatewayReleaseException $exception): void {
            expect($exception->getMessage())->toContain('503')->not->toContain('private partial response body');
        });
    })->with(['up', 'api/v1/gateway/status']);

    it('refuses unhealthy or malformed serving status', function (mixed $status): void {
        Http::fake([
            'https://gateway.test/up' => Http::response('ok'),
            'https://gateway.test/api/v1/gateway/status' => Http::response($status),
        ]);

        expect(fn () => release_verifier()->serving())->toThrow(GatewayReleaseException::class, 'Gateway status is [');
    })->with([[['status' => 'error', 'version' => 'dev']], [['data' => ['status' => 'error', 'version' => 'dev']]], ['not json'], [['data' => 'invalid']]]);

    it('pins the CA and 15-second timeout and does not follow redirects', function (): void {
        $optionsSeen = [];
        Http::fake(function ($request, array $options) use (&$optionsSeen) {
            $optionsSeen[] = $options;

            return Http::response(['status' => 'ok', 'version' => 'dev']);
        });

        release_verifier()->serving();

        expect($optionsSeen)->toHaveCount(2);
        foreach ($optionsSeen as $options) {
            expect($options['verify'])->toBe('/no/such/ca.pem');
            expect($options['timeout'])->toBe(15);
            expect($options['connect_timeout'])->toBe(15);
            expect($options['allow_redirects'])->toBeFalse();
        }
    });
});
