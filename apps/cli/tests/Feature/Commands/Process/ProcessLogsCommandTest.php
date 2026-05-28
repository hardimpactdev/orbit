<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('process:logs', function (): void {
    it('returns bounded logs as a canonical success envelope and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'runtime_unit' => 'orbit_docs_main_vite',
                'lines' => [
                    [
                        'timestamp' => '2026-04-30T12:00:00Z',
                        'message' => 'Vite ready',
                    ],
                ],
            ],
        ], ['line_count' => 1]));

        [$exitCode, $output] = runCommand($this, 'process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--lines' => 5,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/processes/vite/log')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'workspace=feature-docs')
                && str_contains($url, 'lines=5');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['logs']['runtime_unit'])->toBe('orbit_docs_main_vite')
            ->and($decoded['success']['meta']['line_count'])->toBe(1);
    });

    it('renders bounded log lines for human output', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'runtime_unit' => 'orbit_docs_main_vite',
                'lines' => [
                    ['timestamp' => '2026-04-30T12:00:00Z', 'message' => 'Vite ready'],
                    ['timestamp' => null, 'message' => 'plain line'],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('2026-04-30T12:00:00Z Vite ready')
            ->and($output)->toContain('plain line');
    });

    it('fails validation before opening the gateway request when name is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:logs', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('name');
    });

    it('fails validation before opening the gateway request when lines is invalid', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--lines' => 0,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('lines');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
