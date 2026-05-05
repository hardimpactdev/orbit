<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;
use App\Services\Gateway\GatewayResponseParser;
use App\Services\Gateway\Requests\UpdateNodeRequest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

it('returns PUT method', function (): void {
    $request = new UpdateNodeRequest('app-1', ['host' => '10.6.0.8']);

    expect($request->method())->toBe('PUT');
});

it('returns the correct path with node name', function (): void {
    $request = new UpdateNodeRequest('app-1', ['host' => '10.6.0.8']);

    expect($request->path())->toBe('/api/nodes/app-1');
});

it('returns empty query array', function (): void {
    $request = new UpdateNodeRequest('app-1', ['host' => '10.6.0.8']);

    expect($request->query())->toBe([]);
});

it('returns update data', function (): void {
    $request = new UpdateNodeRequest('app-1', [
        'host' => '10.6.0.8',
        'environment' => 'production',
        'public_ipv4' => '203.0.113.10',
        'public_ipv6' => '2001:db8::10',
    ]);

    expect($request->data())->toBe([
        'host' => '10.6.0.8',
        'environment' => 'production',
        'public_ipv4' => '203.0.113.10',
        'public_ipv6' => '2001:db8::10',
    ]);
});

it('implements GatewayRequest', function (): void {
    $request = new UpdateNodeRequest('app-1', ['host' => '10.6.0.8']);

    expect($request)->toBeInstanceOf(GatewayRequest::class);
});

it('supports omitted optional fields', function (): void {
    $request = new UpdateNodeRequest('app-1', [
        'host' => '10.6.0.8',
        'environment' => null,
        'public_ipv4' => null,
    ]);

    expect($request->data())->toBe(['host' => '10.6.0.8']);
});

it('parses success envelope through gateway response parser', function (): void {
    $parser = new GatewayResponseParser;
    $response = updateNodeMockResponse(200, [
        'success' => [
            'data' => [
                'name' => 'app-1',
                'changed' => ['host'],
                'action' => 'updated',
            ],
        ],
    ]);

    $result = $parser->parse($response);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->data())->toBe([
            'name' => 'app-1',
            'changed' => ['host'],
            'action' => 'updated',
        ]);
});

it('parses error envelope through gateway response parser', function (): void {
    $parser = new GatewayResponseParser;
    $response = updateNodeMockResponse(422, [
        'error' => [
            'code' => 'node.field_role_incompatible',
            'message' => "The field 'environment' is not valid for node 'gateway-1' (role: gateway).",
            'meta' => [
                'field' => 'environment',
                'name' => 'gateway-1',
                'role' => 'gateway',
            ],
        ],
    ]);

    $result = $parser->parse($response);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->errorCode())->toBe('node.field_role_incompatible')
        ->and($result->errorMessage())->toBe("The field 'environment' is not valid for node 'gateway-1' (role: gateway).")
        ->and($result->errorMeta())->toBe([
            'field' => 'environment',
            'name' => 'gateway-1',
            'role' => 'gateway',
        ]);
});

/**
 * @param  array<string, mixed>  $body
 */
function updateNodeMockResponse(int $status, array $body): Response
{
    return new Response(new Psr7Response(
        status: $status,
        headers: ['Content-Type' => 'application/json'],
        body: json_encode($body, JSON_THROW_ON_ERROR),
    ));
}
