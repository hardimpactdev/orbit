<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('process:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'workspace' => null],
            'processes' => [
                [
                    'name' => 'vite',
                    'command' => 'npm run dev',
                    'restart_policy' => 'never',
                    'runtime_unit' => 'orbit_docs_main_vite',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', [
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/processes')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'workspace=feature-docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['processes'][0]['name'])->toBe('vite');
    });

    it('renders human output containing process fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'workspace' => null],
            'processes' => [
                ['name' => 'vite', 'command' => 'npm run dev'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', ['--app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('processes');
    });

    it('passes through gateway error envelopes from HTTP failures', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', 'An app context is required.', [
            'field' => 'app',
        ]), 422);

        [$exitCode, $output] = runCommand($this, 'process:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'process:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
