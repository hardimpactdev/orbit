<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('LogStream commands', function (): void {
    it('streams process follow output as plain text from the gateway', function (): void {
        fakeGatewayTextStream("vite ready\nhmr updated\n");

        [$exitCode, $output] = runCommand($this, 'process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--lines' => 5,
            '--follow' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && $request->hasHeader('Accept', 'text/plain')
                && str_contains($url, '/api/processes/vite/log')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'workspace=feature-docs')
                && str_contains($url, 'lines=5')
                && str_contains($url, 'follow=1');
        });

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('vite ready')
            ->and($output)->toContain('hmr updated');
    });

    it('rejects JSON process follow output before opening a stream', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:logs', [
            'name' => 'vite',
            '--app' => 'docs',
            '--follow' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('json');
    });

    it('reads deploy log output as captured finite history', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'run' => ['id' => 42, 'status' => 'failed'],
            'steps' => [
                [
                    'id' => 13,
                    'title' => 'Build assets',
                    'status' => 'failed',
                    'output' => [
                        'stdout' => "npm install\n",
                        'stderr' => "vite failed\n",
                    ],
                ],
            ],
        ], ['app' => 'docs']));

        [$exitCode, $output] = runCommand($this, 'deploy:log', [
            'app' => 'docs',
            'run' => '42',
            '--step' => '13',
            '--lines' => '20',
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/deploy/log/42')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'step=13')
                && str_contains($url, 'lines=20');
        });

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Build assets (failed):')
            ->and($output)->toContain('stdout:')
            ->and($output)->toContain('npm install')
            ->and($output)->toContain('stderr:')
            ->and($output)->toContain('vite failed');
    });

    it('keeps schedule logs as a finite captured-log read without progress events', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'run' => ['id' => 18, 'schedule' => 'laravel-scheduler', 'status' => 'completed'],
            'output' => ['stdout' => "ok\n", 'stderr' => "warning\n"],
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:logs', [
            'name' => 'laravel-scheduler',
            '--app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('stdout')
            ->and($output)->toContain('ok')
            ->and($output)->toContain('stderr')
            ->and($output)->toContain('warning')
            ->and($output)->not->toContain('event:')
            ->and($output)->not->toContain('complete');
    });
});
