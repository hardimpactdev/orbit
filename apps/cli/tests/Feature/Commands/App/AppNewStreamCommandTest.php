<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppNewStream command', function (): void {
    it('emits only the final AppNewStream complete frame in json mode', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'footer' => "App 'docs' created.",
                'app' => ['name' => 'docs', 'node' => 'app-1'],
            ],
        ];

        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', ['title' => 'Creating App'])
            .gatewayProgressFrame('step', ['key' => 'source', 'status' => 'running', 'message' => 'Creating source'])
            .gatewayProgressFrame('complete', $complete),
        );

        [$exitCode, $output] = runCommand($this, 'app:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps'
            && $request->hasHeader('Accept', 'text/event-stream'));

        expect($exitCode)->toBe(0)
            ->and($decoded)->toBe([
                'event' => 'complete',
                'data' => $complete,
            ])
            ->and($output)->not->toContain('Creating source');
    });

    it('preserves AppNewStream gateway errors before a stream starts', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope('authorization_failed', 'Missing app permission.', [
            'missing_permission' => 'app:new',
        ]), JSON_THROW_ON_ERROR), 403);

        [$exitCode, $output] = runCommand($this, 'app:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('app:new');
    });
});
