<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\SetNodePlatformRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('SetNodePlatformRequest', function (): void {
    it('sends only the platform and returns the recorded Node', function (): void {
        $recorded = platform_fixture('nodes/node-platform/updated');
        $request = new SetNodePlatformRequest(2, 'ipados');
        $node = platform_fixture_connector($request, $recorded)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::PATCH)
            ->and($request->resolveEndpoint())->toBe('/api/v1/nodes/2/platform')
            ->and($request->body()->all())->toBe(['platform' => 'ipados'])
            ->and($node)->toBeInstanceOf(NodeResponse::class)
            ->and($node->platform)->toBe('ipados')
            ->and($node->architecture)->toBe('arm64')
            ->and($node->toArray())->toBe([...$recorded['body']['data'], 'request_id' => $recorded['body']['meta']['request_id']]);
    });

    it('preserves the recorded refusal of a managed Node', function (): void {
        $recorded = platform_fixture('nodes/node-platform/managed-refused');
        $request = new SetNodePlatformRequest(2, 'ipados');

        expect(fn () => platform_fixture_connector($request, $recorded)->send($request)->dto())
            ->toThrow(function (GatewayApiException $exception) use ($recorded): void {
                expect($exception->errorCode())->toBe('node.platform_managed')
                    ->and($exception->getMessage())->toBe($recorded['body']['error']['message']);
            });
    });
});

/** @return array<string, mixed> */
function platform_fixture(string $name): array
{
    $decoded = json_decode(
        (string) file_get_contents(dirname(__DIR__, levels: 4)."/fixtures/{$name}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    assert(is_array($decoded));

    return $decoded;
}

/** @param array<string, mixed> $recorded */
function platform_fixture_connector(SetNodePlatformRequest $request, array $recorded): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient(new MockClient([
        $request::class => MockResponse::make($recorded['body'], $recorded['status']),
    ]));

    return $connector;
}
