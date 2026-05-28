<?php

declare(strict_types=1);

use Orbit\Core\Http\JsonEnvelope;

describe(JsonEnvelope::class, function (): void {
    it('returns a success envelope with empty data and meta by default', function (): void {
        expect(JsonEnvelope::success())->toBe([
            'success' => [
                'data' => [],
                'meta' => [],
            ],
        ]);
    });

    it('returns a success envelope with data', function (): void {
        expect(JsonEnvelope::success([
            'node' => 'gateway',
        ]))->toBe([
            'success' => [
                'data' => [
                    'node' => 'gateway',
                ],
                'meta' => [],
            ],
        ]);
    });

    it('returns a success envelope with data and meta', function (): void {
        expect(JsonEnvelope::success([
            'node' => 'gateway',
        ], [
            'request_id' => 'req-123',
        ]))->toBe([
            'success' => [
                'data' => [
                    'node' => 'gateway',
                ],
                'meta' => [
                    'request_id' => 'req-123',
                ],
            ],
        ]);
    });

    it('returns a failure envelope with empty meta by default', function (): void {
        expect(JsonEnvelope::failure('invalid_request', 'The request is invalid.'))->toBe([
            'error' => [
                'code' => 'invalid_request',
                'message' => 'The request is invalid.',
                'meta' => [],
            ],
        ]);
    });

    it('returns a failure envelope with meta', function (): void {
        expect(JsonEnvelope::failure('expired_token', 'The operation token expired.', [
            'request_id' => 'req-456',
        ]))->toBe([
            'error' => [
                'code' => 'expired_token',
                'message' => 'The operation token expired.',
                'meta' => [
                    'request_id' => 'req-456',
                ],
            ],
        ]);
    });

    it('returns exactly the expected failure error keys', function (): void {
        $envelope = JsonEnvelope::failure('not_found', 'The resource was not found.');

        expect(array_keys($envelope['error']))->toBe([
            'code',
            'message',
            'meta',
        ]);
    });

    it('always includes stable envelope keys when values are empty', function (): void {
        expect(array_keys(JsonEnvelope::success()))->toBe([
            'success',
        ]);

        expect(array_keys(JsonEnvelope::failure('unknown', 'Unknown error.')))->toBe([
            'error',
        ]);
    });
});
