<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Nodes\NodeAgentResponse;

it('reads the agent liveness and labels it for people', function (): void {
    $offline = NodeAgentResponse::tryFromGatewayData(['state' => 'offline', 'since' => '2026-11-02T17:55:00Z']);

    expect($offline?->toArray())->toBe(['state' => 'offline', 'since' => '2026-11-02T17:55:00Z'])
        ->and($offline?->label())->toBe('offline since 2026-11-02T17:55:00Z')
        ->and($offline?->stateLabel())->toBe('offline')
        ->and(NodeAgentResponse::tryFromGatewayData(['state' => 'not_seen', 'since' => null])?->label())->toBe('not seen')
        ->and(NodeAgentResponse::tryFromGatewayData(['state' => 'online'])?->label())->toBe('online')
        ->and(NodeAgentResponse::tryFromGatewayData(['state' => 'asleep']))->toBeNull()
        ->and(NodeAgentResponse::tryFromGatewayData(null))->toBeNull();
});
