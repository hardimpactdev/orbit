<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway;

use App\Services\Gateway\GatewayResponse;
use App\Services\Gateway\GatewayResponseParser;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use RuntimeException;

describe('GatewayResponse', function (): void {
    it('returns success state', function (): void {
        $response = GatewayResponse::success(data: ['key' => 'value'], meta: ['page' => 1]);

        expect($response->isSuccess())->toBeTrue();
        expect($response->data())->toBe(['key' => 'value']);
        expect($response->meta())->toBe(['page' => 1]);
        expect($response->errorCode())->toBeNull();
        expect($response->errorMessage())->toBeNull();
        expect($response->errorMeta())->toBe([]);
    });

    it('returns error state', function (): void {
        $response = GatewayResponse::error(code: 'not_found', message: 'Node not found', errorMeta: ['id' => 1]);

        expect($response->isSuccess())->toBeFalse();
        expect($response->data())->toBe([]);
        expect($response->meta())->toBe([]);
        expect($response->errorCode())->toBe('not_found');
        expect($response->errorMessage())->toBe('Node not found');
        expect($response->errorMeta())->toBe(['id' => 1]);
    });
});

describe('GatewayResponseParser', function (): void {
    it('parses nested success envelope', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, ['success' => ['data' => ['name' => 'test']]]);
        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data())->toBe(['name' => 'test']);
        expect($result->meta())->toBe([]);
    });

    it('parses flat success envelope', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, ['success' => true, 'data' => ['root_ca' => 'pem']]);
        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data())->toBe(['root_ca' => 'pem']);
        expect($result->meta())->toBe([]);
    });

    it('parses nested error envelope', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, [
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'Forbidden',
                'meta' => ['caller_role' => 'app'],
            ],
        ]);
        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeFalse();
        expect($result->errorCode())->toBe('caller_role_not_allowed');
        expect($result->errorMessage())->toBe('Forbidden');
        expect($result->errorMeta())->toBe(['caller_role' => 'app']);
    });

    it('parses flat error envelope', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, ['error' => 'Something went wrong']);
        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeFalse();
        expect($result->errorCode())->toBeNull();
        expect($result->errorMessage())->toBe('Something went wrong');
        expect($result->errorMeta())->toBe([]);
    });

    it('parses doctor key as success', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, ['doctor' => ['status' => 'ok']]);
        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data())->toBe(['doctor' => ['status' => 'ok']]);
    });

    it('handles missing meta in success envelope', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, ['success' => ['data' => ['name' => 'test']]]);
        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data())->toBe(['name' => 'test']);
        expect($result->meta())->toBe([]);
    });

    it('throws on HTTP failure', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(500, ['error' => 'Server error']);

        expect(fn () => $parser->parse($response))
            ->toThrow(RuntimeException::class, 'Gateway request failed with HTTP status 500');
    });

    it('parses gateway error envelopes even when HTTP status failed', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(422, [
            'error' => [
                'code' => 'node.provisioning_incomplete',
                'message' => 'Install failed',
                'meta' => ['step' => 'install_orbit'],
            ],
        ]);

        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeFalse();
        expect($result->errorCode())->toBe('node.provisioning_incomplete');
        expect($result->errorMessage())->toBe('Install failed');
        expect($result->errorMeta())->toBe(['step' => 'install_orbit']);
    });

    it('throws on empty body', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, '');

        expect(fn () => $parser->parse($response))
            ->toThrow(RuntimeException::class, 'Gateway response body is empty.');
    });

    it('throws on invalid JSON', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, 'not json');

        expect(fn () => $parser->parse($response))
            ->toThrow(RuntimeException::class, 'Gateway response is not valid JSON.');
    });

    it('treats unrecognized payload as success', function (): void {
        $parser = new GatewayResponseParser;
        $response = mockResponse(200, ['foo' => 'bar']);
        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeTrue();
        expect($result->data())->toBe(['foo' => 'bar']);
    });
});

function mockResponse(int $status, array|string $body): Response
{
    $bodyString = is_array($body) ? json_encode($body) : $body;

    return new Response(
        response: new Psr7Response(status: $status, body: $bodyString),
    );
}
