<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;
use App\Services\Gateway\GatewayResponseParser;
use App\Services\Gateway\Requests\GrantNodeRequest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

it('returns POST method', function (): void {
    $request = new GrantNodeRequest('control-1', 'app-1');

    expect($request->method())->toBe('POST');
});

it('returns the grant endpoint path', function (): void {
    $request = new GrantNodeRequest('control-1', 'app-1');

    expect($request->path())->toBe('/api/nodes/grant');
});

it('returns empty query array', function (): void {
    $request = new GrantNodeRequest('control-1', 'app-1');

    expect($request->query())->toBe([]);
});

it('returns grant data', function (): void {
    $request = new GrantNodeRequest('control-1', 'app-1');

    expect($request->data())->toBe([
        'consuming_node' => 'control-1',
        'serving_node' => 'app-1',
    ]);
});

it('implements GatewayRequest', function (): void {
    $request = new GrantNodeRequest('control-1', 'app-1');

    expect($request)->toBeInstanceOf(GatewayRequest::class);
});

it('parses success envelope through gateway response parser', function (): void {
    $parser = new GatewayResponseParser;
    $response = grantNodeMockResponse(200, [
        'success' => [
            'data' => [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'action' => 'granted',
                'already_granted' => false,
            ],
        ],
    ]);

    $result = $parser->parse($response);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->data())->toBe([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'action' => 'granted',
            'already_granted' => false,
        ]);
});

it('parses error envelope through gateway response parser', function (): void {
    $parser = new GatewayResponseParser;
    $response = grantNodeMockResponse(422, [
        'error' => [
            'code' => 'node.grant_policy_violation',
            'message' => 'A node cannot be granted access to itself.',
            'meta' => [
                'consuming_node' => 'control-1',
                'serving_node' => 'control-1',
                'reason' => 'self_grant',
            ],
        ],
    ]);

    $result = $parser->parse($response);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->errorCode())->toBe('node.grant_policy_violation')
        ->and($result->errorMessage())->toBe('A node cannot be granted access to itself.')
        ->and($result->errorMeta())->toBe([
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
            'reason' => 'self_grant',
        ]);
});

/**
 * @param  array<string, mixed>  $body
 */
function grantNodeMockResponse(int $status, array $body): Response
{
    return new Response(new Psr7Response(
        status: $status,
        headers: ['Content-Type' => 'application/json'],
        body: json_encode($body, JSON_THROW_ON_ERROR),
    ));
}
