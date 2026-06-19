<?php

declare(strict_types=1);

use App\Services\GatewayLogStreamClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('GatewayLogStreamClient', function (): void {
    it('streams plain text chunks to the output callback and returns 0', function (): void {
        Http::fake([
            'https://gateway.test/api/logs*' => Http::response("line one\nline two\n", 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]),
        ]);

        $output = '';
        $exitCode = (new GatewayLogStreamClient('https://gateway.test', 30))
            ->streamText('/api/logs', ['lines' => 5], function (string $chunk) use (&$output): void {
                $output .= $chunk;
            });

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('line one')
            ->and($output)->toContain('line two');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Accept', 'text/plain'));
    });

    it('verifies TLS against the configured gateway CA when a PEM file exists', function (): void {
        $pemPath = tempnam(sys_get_temp_dir(), 'orbit-ca-').'.pem';
        file_put_contents($pemPath, "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");

        $options = [];

        Http::fake(function (Request $request, array $opts) use (&$options) {
            $options = $opts;

            return Http::response('ok', 200, ['Content-Type' => 'text/plain']);
        });

        try {
            (new GatewayLogStreamClient('https://gateway.test', 30, $pemPath))
                ->streamText('/api/logs', [], fn () => null);
        } finally {
            @unlink($pemPath);
        }

        expect($options['verify'] ?? null)->toBe($pemPath)
            ->and($options['stream'] ?? null)->toBeTrue()
            ->and($options['read_timeout'] ?? null)->toBe(0);
    });

    it('disables idle read timeout so long silent log streams can complete', function (): void {
        $options = [];

        Http::fake(function (Request $request, array $opts) use (&$options) {
            $options = $opts;

            return Http::response('ok', 200, ['Content-Type' => 'text/plain']);
        });

        (new GatewayLogStreamClient('https://gateway.test', 30))
            ->streamText('/api/logs', [], fn () => null);

        expect($options['stream'] ?? null)->toBeTrue()
            ->and($options['read_timeout'] ?? null)->toBe(0);
    });

    it('leaves the default verify behavior when no CA PEM path is configured', function (): void {
        $verify = null;

        Http::fake(function (Request $request, array $opts) use (&$verify) {
            $verify = $opts['verify'] ?? null;

            return Http::response('ok', 200, ['Content-Type' => 'text/plain']);
        });

        (new GatewayLogStreamClient('https://gateway.test', 30))
            ->streamText('/api/logs', [], fn () => null);

        expect($verify)->not->toBeString();
    });
});
