<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;
use App\Services\Gateway\GatewayResponseParser;
use App\Services\Gateway\Requests\DefaultNodeRequest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

it('creates show requests', function (): void {
    $request = DefaultNodeRequest::show();

    expect($request->method())->toBe('GET')
        ->and($request->path())->toBe('/api/nodes/default')
        ->and($request->query())->toBe([])
        ->and($request->data())->toBe([]);
});

it('creates set requests', function (): void {
    $request = DefaultNodeRequest::set('app-1');

    expect($request->method())->toBe('PUT')
        ->and($request->path())->toBe('/api/nodes/default')
        ->and($request->query())->toBe([])
        ->and($request->data())->toBe(['name' => 'app-1']);
});

it('creates clear requests', function (): void {
    $request = DefaultNodeRequest::clear();

    expect($request->method())->toBe('DELETE')
        ->and($request->path())->toBe('/api/nodes/default')
        ->and($request->query())->toBe([])
        ->and($request->data())->toBe([]);
});

it('implements GatewayRequest', function (): void {
    expect(DefaultNodeRequest::show())->toBeInstanceOf(GatewayRequest::class);
});

it('parses success envelope through gateway response parser', function (): void {
    $parser = new GatewayResponseParser;
    $response = defaultNodeMockResponse(200, [
        'success' => [
            'data' => [
                'action' => 'set',
                'default_node' => [
                    'name' => 'app-1',
                    'role' => 'app',
                    'environment' => 'development',
                ],
            ],
        ],
    ]);

    $result = $parser->parse($response);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->data())->toBe([
            'action' => 'set',
            'default_node' => [
                'name' => 'app-1',
                'role' => 'app',
                'environment' => 'development',
            ],
        ]);
});

it('parses error envelope through gateway response parser', function (): void {
    $parser = new GatewayResponseParser;
    $response = defaultNodeMockResponse(403, [
        'error' => [
            'code' => 'authorization_failed',
            'message' => "This node is not authorized to operate on 'app-1'.",
            'meta' => [
                'name' => 'app-1',
                'caller_role' => 'control',
            ],
        ],
    ]);

    $result = $parser->parse($response);

    expect($result->isSuccess())->toBeFalse()
        ->and($result->errorCode())->toBe('authorization_failed')
        ->and($result->errorMessage())->toBe("This node is not authorized to operate on 'app-1'.")
        ->and($result->errorMeta())->toBe([
            'name' => 'app-1',
            'caller_role' => 'control',
        ]);
});

/**
 * @param  array<string, mixed>  $body
 */
function defaultNodeMockResponse(int $status, array $body): Response
{
    return new Response(new Psr7Response(
        status: $status,
        headers: ['Content-Type' => 'application/json'],
        body: json_encode($body, JSON_THROW_ON_ERROR),
    ));
}
