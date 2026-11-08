<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Nodes\NodeMetricsResponse;

it('preserves snapshot values, observation time and liveness from the Gateway', function (): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../../../../fixtures/nodes/node-metrics/default.json'), true, flags: JSON_THROW_ON_ERROR);
    $response = NodeMetricsResponse::fromGatewayData($fixture['body']['data'], $fixture['body']['meta']['request_id']);
    expect($response->toArray())->toEqual([...$fixture['body']['data'], 'request_id' => $fixture['body']['meta']['request_id']])
        ->and($response->observedAt)->toBe('2026-11-06T10:00:00Z')
        ->and($response->agent?->label())->toBe('online')->and($response->possiblyOutdated)->toBeFalse();
});

it('preserves absent liveness without manufacturing an online agent', function (): void {
    $response = NodeMetricsResponse::fromGatewayData([
        'node_id' => 7, 'node_name' => 'old-node', 'observed_at' => '2026-11-06T10:00:00Z',
        'agent' => null, 'possibly_outdated' => true,
    ], 'read-id');
    expect($response->toArray()['agent'])->toBeNull()
        ->and($response->possiblyOutdated)->toBeTrue()->and($response->observedAt)->toBe('2026-11-06T10:00:00Z');
});
