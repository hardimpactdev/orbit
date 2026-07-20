<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppWebSocketCredentialsCommand', function (): void {
    it('requests app websocket credentials from the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'credentials' => [
                'instance' => 'docs',
                'internal_host' => 'websocket.orbit',
                'public_hosts' => ['ws.docs.test'],
                'allowed_origins' => ['https://docs.test'],
                'reverb_app_id' => 'docs',
                'reverb_app_key' => 'reverb-key',
                'reverb_app_secret' => 'reverb-secret',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:websocket credentials', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/instances/docs/websocket/credentials'
                && $request->data() === []
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['credentials']['internal_host'])
            ->toBe('websocket.orbit')
            ->and($decoded['success']['data']['credentials']['reverb_app_key'])
            ->toBe('reverb-key')
            ->and($decoded['success']['data']['credentials']['reverb_app_secret'])
            ->toBe('reverb-secret');
    });

    it('renders credentials responses in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'credentials' => [
                'instance' => 'docs',
                'internal_host' => 'websocket.orbit',
                'public_hosts' => ['ws.docs.test'],
                'allowed_origins' => ['https://docs.test'],
                'reverb_app_id' => 'docs',
                'reverb_app_key' => 'reverb-key',
                'reverb_app_secret' => 'reverb-secret',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:websocket credentials', [
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('credentials:')
            ->and($output)
            ->toContain('  instance: docs')
            ->and($output)
            ->toContain('  internal_host: websocket.orbit')
            ->and($output)
            ->toContain('  public_hosts:')
            ->and($output)
            ->toContain('    - ws.docs.test')
            ->and($output)
            ->toContain('  allowed_origins:')
            ->and($output)
            ->toContain('    - https://docs.test')
            ->and($output)
            ->toContain('  reverb_app_id: docs')
            ->and($output)
            ->toContain('  reverb_app_key: reverb-key')
            ->and($output)
            ->toContain('  reverb_app_secret: reverb-secret')
            ->and($output)
            ->not->toContain('{');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'instance:websocket credentials', [
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
            "This node is not authorized for 'app:credentials' on 'app-1'.",
            ['missing_permission' => 'app:credentials', 'serving_node' => 'app-1'],
        ), 403);

        [$exitCode, $output] = runCommand($this, 'instance:websocket credentials', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])
            ->toBe('app:credentials');
    });
});
