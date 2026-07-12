<?php

declare(strict_types=1);

use Orbit\Sdk\Laravel\Testing\GatewayMockClient;

test('streamed tool:update requests use their fixed Agent-push lane without a transport selector', function (): void {
    fakeGatewayProgressStream(body: gatewayProgressFrame(event: 'complete', data: [
        'exit_code' => 0,
        'data' => [
            'tool' => ['name' => 'caddy', 'node' => 'beast', 'version' => null],
        ],
    ]));

    [$exitCode] = runCommand(test: $this, command: 'tool:update', params: [
        'tool' => 'caddy',
        '--node' => 'beast',
        '--stream-json' => true,
    ]);

    assertGatewayStreamSent(
        callback: fn (FakeGatewayStreamRequest $request): bool => (
            $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/caddy/update'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === ['node' => 'beast']
        ),
    );

    $pendingRequest = GatewayMockClient::lastPendingRequest();

    expect($exitCode)
        ->toBe(0)
        ->and($pendingRequest?->header('X-Orbit-Node-Transport-Preference'))
        ->toBeNull();
});
