<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;
use App\Services\Gateway\GatewayResponseParser;
use App\Services\Gateway\Requests\RevokeNodeRequest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

it('returns POST method', function (): void {
    $request = new RevokeNodeRequest('control-1', 'app-1');

    expect($request->method())->toBe('POST');
});

it('returns the revoke endpoint path', function (): void {
    $request = new RevokeNodeRequest('control-1', 'app-1');

    expect($request->path())->toBe('/api/nodes/revoke');
});

it('returns empty query array', function (): void {
    $request = new RevokeNodeRequest('control-1', 'app-1');

    expect($request->query())->toBe([]);
});

it('returns revoke data with destructive consent', function (): void {
    $request = new RevokeNodeRequest('control-1', 'app-1');

    expect($request->data())->toBe([
        'consuming_node' => 'control-1',
        'serving_node' => 'app-1',
        'force' => true,
    ]);
});

it('implements GatewayRequest', function (): void {
    $request = new RevokeNodeRequest('control-1', 'app-1');

    expect($request)->toBeInstanceOf(GatewayRequest::class);
});

it('parses success envelope through gateway response parser', function (): void {
    $parser = new GatewayResponseParser;
    $response = revokeNodeMockResponse(200, [
        'success' => [
            'data' => [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'action' => 'revoked',
                'already_absent' => false,
                'self_lockout' => false,
            ],
        ],
    ]);

    $result = $parser->parse($response);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->data())->toBe([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'action' => 'revoked',
            'already_absent' => false,
            'self_lockout' => false,
        ]);
});

it('parses error envelope through gateway response parser', function (): void {
    $parser = new GatewayResponseParser;
    $response = revokeNodeMockResponse(403, [
        'error' => [
            'code' => 'authorization_failed',
            'message' => 'This control node is not authorized to revoke grants.',
            'meta' => [
                'required_node' => 'gateway-1',
                'caller_role' => 'control',
            ],
        ],
    ]);

    $result = $parser->parse($response);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->errorCode())->toBe('authorization_failed')
        ->and($result->errorMessage())->toBe('This control node is not authorized to revoke grants.')
        ->and($result->errorMeta())->toBe([
            'required_node' => 'gateway-1',
            'caller_role' => 'control',
        ]);
});

/**
 * @param  array<string, mixed>  $body
 */
function revokeNodeMockResponse(int $status, array $body): Response
{
    return new Response(new Psr7Response(
        status: $status,
        headers: ['Content-Type' => 'application/json'],
        body: json_encode($body, JSON_THROW_ON_ERROR),
    ));
}
