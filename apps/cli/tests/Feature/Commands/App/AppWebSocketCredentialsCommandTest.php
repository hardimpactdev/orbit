<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppWebSocketCredentialsCommand', function (): void {
    it('requests app websocket credentials from the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'credentials' => [
                'app' => 'docs',
                'internal_host' => 'websocket.orbit',
                'public_hosts' => ['ws.docs.test'],
                'allowed_origins' => ['https://docs.test'],
                'reverb_app_id' => 'docs',
                'reverb_app_key' => 'reverb-key',
                'reverb_app_secret' => 'reverb-secret',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:websocket credentials', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/apps/docs/websocket/credentials'
            && $request->data() === []);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['credentials']['internal_host'])->toBe('websocket.orbit')
            ->and($decoded['success']['data']['credentials']['reverb_app_key'])->toBe('reverb-key')
            ->and($decoded['success']['data']['credentials']['reverb_app_secret'])->toBe('reverb-secret');
    });

    it('renders credentials responses in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'credentials' => [
                'app' => 'docs',
                'internal_host' => 'websocket.orbit',
                'public_hosts' => ['ws.docs.test'],
                'allowed_origins' => ['https://docs.test'],
                'reverb_app_id' => 'docs',
                'reverb_app_key' => 'reverb-key',
                'reverb_app_secret' => 'reverb-secret',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:websocket credentials', [
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('credentials:')
            ->and($output)->toContain('websocket.orbit')
            ->and($output)->toContain('reverb-secret');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'app:websocket credentials', [
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
            "This node is not authorized for 'app:credentials' on 'app-1'.",
            ['missing_permission' => 'app:credentials', 'serving_node' => 'app-1'],
        ), 403);

        [$exitCode, $output] = runCommand($this, 'app:websocket credentials', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('app:credentials');
    });
});
