<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;
use App\Services\Gateway\GatewayResponseParser;
use App\Services\Gateway\Requests\RemoveNodeRequest;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

describe('RemoveNodeRequest', function (): void {
    it('returns DELETE method', function (): void {
        $request = new RemoveNodeRequest('app-1');

        expect($request->method())->toBe('DELETE');
    });

    it('returns the remove endpoint path with node name', function (): void {
        $request = new RemoveNodeRequest('app-1');

        expect($request->path())->toBe('/api/nodes/app-1');
    });

    it('returns empty query array', function (): void {
        $request = new RemoveNodeRequest('app-1');

        expect($request->query())->toBe([]);
    });

    it('returns destructive consent data', function (): void {
        $request = new RemoveNodeRequest('app-1');

        expect($request->data())->toBe([
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ]);
    });

    it('supports interactive destructive consent source', function (): void {
        $request = new RemoveNodeRequest('app-1', 'interactive_confirm');

        expect($request->data())->toBe([
            'destructive_consent' => true,
            'destructive_consent_source' => 'interactive_confirm',
        ]);
    });

    it('implements GatewayRequest', function (): void {
        $request = new RemoveNodeRequest('app-1');

        expect($request)->toBeInstanceOf(GatewayRequest::class);
    });

    it('parses success envelope through gateway response parser', function (): void {
        $parser = new GatewayResponseParser;
        $response = removeNodeMockResponse(200, [
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'action' => 'removed',
                    'removed_self' => false,
                    'wireguard_peer_removed' => false,
                    'grants_removed' => 2,
                ],
            ],
        ]);

        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeTrue()
            ->and($result->data())->toBe([
                'name' => 'app-1',
                'action' => 'removed',
                'removed_self' => false,
                'wireguard_peer_removed' => false,
                'grants_removed' => 2,
            ]);
    });

    it('parses error envelope through gateway response parser', function (): void {
        $parser = new GatewayResponseParser;
        $response = removeNodeMockResponse(422, [
            'error' => [
                'code' => 'node.gateway_removal_denied',
                'message' => 'The gateway node cannot be removed with this command.',
                'meta' => [
                    'name' => 'gateway-1',
                    'role' => 'gateway',
                ],
            ],
        ]);

        $result = $parser->parse($response);

        expect($result->isSuccess())->toBeFalse()
            ->and($result->errorCode())->toBe('node.gateway_removal_denied')
            ->and($result->errorMessage())->toBe('The gateway node cannot be removed with this command.')
            ->and($result->errorMeta())->toBe([
                'name' => 'gateway-1',
                'role' => 'gateway',
            ]);
    });
});

/**
 * @param  array<string, mixed>  $body
 */
function removeNodeMockResponse(int $status, array $body): Response
{
    return new Response(new Psr7Response(
        status: $status,
        headers: ['Content-Type' => 'application/json'],
        body: json_encode($body, JSON_THROW_ON_ERROR),
    ));
}
