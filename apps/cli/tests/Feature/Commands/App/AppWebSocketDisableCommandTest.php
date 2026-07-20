<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppWebSocketDisableCommand', function (): void {
    it('requests app websocket disable through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'instance' => 'docs',
                'internal_host' => 'websocket.orbit',
                'public_hosts' => [],
                'allowed_origins' => ['https://docs.test'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:websocket disable', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/instances/docs/websocket/disable'
                && $request->data() === []
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['binding']['internal_host'])
            ->toBe('websocket.orbit')
            ->and($decoded['success']['data']['binding']['public_hosts'])
            ->toBe([]);
    });

    it('renders disable responses in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'instance' => 'docs',
                'internal_host' => 'websocket.orbit',
                'public_hosts' => [],
                'allowed_origins' => ['https://docs.test'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:websocket disable', [
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('binding:')
            ->and($output)
            ->toContain('  instance: docs')
            ->and($output)
            ->toContain('  internal_host: websocket.orbit')
            ->and($output)
            ->toContain('  public_hosts: []')
            ->and($output)
            ->toContain('  allowed_origins:')
            ->and($output)
            ->toContain('    - https://docs.test')
            ->and($output)
            ->not->toContain('{');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'instance:websocket disable', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('instance');
    });

    it('maps gateway failures into canonical CLI failures', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'authorization_failed',
            "This node is not authorized for 'instance:write' on 'app-1'.",
            ['missing_permission' => 'instance:write', 'serving_node' => 'app-1'],
        ), 403);

        [$exitCode, $output] = runCommand($this, 'instance:websocket disable', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])
            ->toBe('instance:write');
    });
});
