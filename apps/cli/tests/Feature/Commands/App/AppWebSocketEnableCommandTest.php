<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppWebSocketEnableCommand', function (): void {
    it('forwards enable payloads to the gateway app websocket endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'app' => 'docs',
                'internal_host' => 'websocket.orbit',
                'public_hosts' => ['ws.docs.test', 'events.docs.test'],
                'allowed_origins' => ['https://docs.test'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:websocket enable', [
            'app' => 'docs',
            '--host' => ['ws.docs.test', 'events.docs.test'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/docs/websocket/enable'
            && $request->data() === [
                'public_hosts' => ['ws.docs.test', 'events.docs.test'],
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['binding']['internal_host'])->toBe('websocket.orbit')
            ->and($decoded['success']['data']['binding']['public_hosts'])->toBe(['ws.docs.test', 'events.docs.test']);
    });

    it('renders enable responses in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'app' => 'docs',
                'internal_host' => 'websocket.orbit',
                'public_hosts' => ['ws.docs.test'],
                'allowed_origins' => ['https://docs.test'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:websocket enable', [
            'app' => 'docs',
            '--host' => ['ws.docs.test'],
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('binding:')
            ->and($output)->toContain('ws.docs.test');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'app:websocket enable', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('maps gateway failures into canonical CLI failures', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'authorization_failed',
            "This node is not authorized for 'app:write' on 'app-1'.",
            ['missing_permission' => 'app:write', 'serving_node' => 'app-1'],
        ), 403);

        [$exitCode, $output] = runCommand($this, 'app:websocket enable', [
            'app' => 'docs',
            '--host' => ['ws.docs.test'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('app:write');
    });
});
