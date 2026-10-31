<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('SetNodeWireGuardKeyRequest', function (): void {
    it('transports the public key without policy and returns the admitted Operation DTO', function (string $key): void {
        $recorded = json_decode((string) file_get_contents(dirname(__DIR__, levels: 4).'/fixtures/nodes/node-wireguard-key/updated.json'), true, flags: JSON_THROW_ON_ERROR);
        $request = new SetNodeWireGuardKeyRequest($recorded['body']['data']['target_id'], $key);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient(new MockClient([
            SetNodeWireGuardKeyRequest::class => MockResponse::make($recorded['body'], $recorded['status']),
        ]));
        $node = $connector->send($request)->dto();
        expect($request->getMethod())->toBe(Method::PATCH)
            ->and($request->resolveEndpoint())->toBe('/api/v1/nodes/3/wireguard-key')
            ->and($request->body()->all())->toBe(['wireguard_public_key' => $key])
            ->and($node)->toBeInstanceOf(OperationResponse::class)
            ->and($node->id)->toBe(1)->and($node->kind)->toBe('node.wireguard_key')
            ->and($node->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    })->with([base64_encode(str_repeat('n', 32)), '', ' malformed ']);
});
