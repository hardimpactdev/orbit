<?php

declare(strict_types=1);

use App\Services\Gateway\FetchGatewayRootCa;
use Illuminate\Support\Facades\Http;

describe('FetchGatewayRootCa', function (): void {
    beforeEach(function (): void {
        $this->fetcher = new FetchGatewayRootCa;
    });

    it('fetches root CA from gateway', function (): void {
        $pem = "-----BEGIN CERTIFICATE-----\nTESTPEM\n-----END CERTIFICATE-----\n";
        Http::fake([
            'http://10.6.0.2/api/ca/root' => Http::response([
                'success' => [
                    'data' => [
                        'root_ca' => $pem,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->fetcher->handle('10.6.0.2');

        expect($result->pem)->toBe($pem)
            ->and($result->sha256)->toBe(hash('sha256', $pem))
            ->and($result->sourceUrl)->toBe('https://10.6.0.2/api/ca/root');
    });

    it('follows same-host HTTPS redirect', function (): void {
        $pem = "-----BEGIN CERTIFICATE-----\nTESTPEM\n-----END CERTIFICATE-----\n";
        Http::fake([
            'http://10.6.0.2/api/ca/root' => Http::response('', 302, ['Location' => 'https://10.6.0.2/api/ca/root']),
            'https://10.6.0.2/api/ca/root' => Http::response([
                'success' => [
                    'data' => [
                        'root_ca' => $pem,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->fetcher->handle('10.6.0.2');

        expect($result->pem)->toBe($pem);
    });

    it('rejects empty CA response', function (): void {
        Http::fake([
            'http://10.6.0.2/api/ca/root' => Http::response('', 200),
        ]);

        expect(fn () => $this->fetcher->handle('10.6.0.2'))
            ->toThrow(RuntimeException::class, 'invalid or empty CA');
    });

    it('rejects non-PEM content', function (): void {
        Http::fake([
            'http://10.6.0.2/api/ca/root' => Http::response('not a pem', 200),
        ]);

        expect(fn () => $this->fetcher->handle('10.6.0.2'))
            ->toThrow(RuntimeException::class, 'non-PEM content');
    });

    it('unwraps legacy data.root_ca envelope', function (): void {
        $pem = "-----BEGIN CERTIFICATE-----\nTESTPEM\n-----END CERTIFICATE-----\n";
        Http::fake([
            'http://10.6.0.2/api/ca/root' => Http::response([
                'data' => [
                    'root_ca' => $pem,
                ],
            ], 200),
        ]);

        $result = $this->fetcher->handle('10.6.0.2');

        expect($result->pem)->toBe($pem);
    });

    it('unwraps JSON-only payload with nested success.data.root_ca', function (): void {
        $pem = "-----BEGIN CERTIFICATE-----\nTESTPEM\n-----END CERTIFICATE-----\n";
        Http::fake([
            'http://10.6.0.2/api/ca/root' => Http::response(
                json_encode(['success' => ['data' => ['root_ca' => $pem]]]),
                200,
            ),
        ]);

        $result = $this->fetcher->handle('10.6.0.2');

        expect($result->pem)->toBe($pem);
    });
});
