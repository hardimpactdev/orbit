<?php

declare(strict_types=1);

use App\Http\Mcp\ApiResult;

describe(ApiResult::class, function (): void {
    it('returns a JSON document as it is', function (): void {
        expect(new ApiResult(200, '{"data":{"id":1}}')->json())->toBe(['data' => ['id' => 1]]);
    });

    it('preserves nested empty JSON objects and lists', function (): void {
        $result = new ApiResult(200, '{"data":{"options":{},"flags":[],"items":[{"options":{}}]}}');

        expect(json_encode($result->json(), JSON_THROW_ON_ERROR))->toBe('{"data":{"options":{},"flags":[],"items":[{"options":{}}]}}');
    });

    it('collects a newline-delimited stream into events', function (): void {
        $result = new ApiResult(200, "{\"type\":\"phase\",\"phase\":\"prepare\"}\n{\"type\":\"result\",\"status\":\"succeeded\"}\n");

        expect($result->json())->toBe(['events' => [
            ['type' => 'phase', 'phase' => 'prepare'],
            ['type' => 'result', 'status' => 'succeeded'],
        ]]);
    });

    it('has no document for an empty or non-JSON body', function (): void {
        expect(new ApiResult(204, '')->json())->toBeNull()
            ->and(new ApiResult(200, '<html></html>')->json())->toBeNull();
    });

    it('fails from status 400 upward', function (): void {
        expect(new ApiResult(399, '')->failed())->toBeFalse()
            ->and(new ApiResult(422, '')->failed())->toBeTrue();
    });
});
