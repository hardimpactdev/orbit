<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('SetNodeWireGuardKeyRequest', function (): void {
    it('transports the public key without policy and returns the standard Node DTO', function (string $key): void {
        $request = new SetNodeWireGuardKeyRequest(2, $key);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient(new MockClient([
            SetNodeWireGuardKeyRequest::class => MockResponse::make([
                'data' => [
                    'id' => 2, 'name' => 'laptop', 'status' => 'active',
                    'public_ssh_host' => '192.0.2.2', 'public_ssh_port' => 22, 'user' => 'orbit',
                    'wireguard_public_key' => base64_encode(str_repeat('n', 32)), 'roles' => [],
                ],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
        ]));
        $node = $connector->send($request)->dto();
        expect($request->getMethod())->toBe(Method::PATCH)
            ->and($request->resolveEndpoint())->toBe('/api/v1/nodes/2/wireguard-key')
            ->and($request->body()->all())->toBe(['wireguard_public_key' => $key])
            ->and($node)->toBeInstanceOf(NodeResponse::class)
            ->and($node->id)->toBe(2)->and($node->wireguardPublicKey)->toBe(base64_encode(str_repeat('n', 32)))
            ->and($node->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    })->with([base64_encode(str_repeat('n', 32)), '', ' malformed ']);
});
