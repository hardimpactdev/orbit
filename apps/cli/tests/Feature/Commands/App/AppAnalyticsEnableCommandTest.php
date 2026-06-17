<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppAnalyticsEnableCommand', function (): void {
    it('forwards enable payloads to the gateway app analytics endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'app' => 'docs',
                'enabled' => true,
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => ['analytics.docs.test', 'metrics.docs.test'],
                'tracking_paths' => ['/js/*', '/api/event'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:analytics enable', [
            'app' => 'docs',
            '--host' => ['analytics.docs.test', 'metrics.docs.test'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/docs/analytics/enable'
            && $request->data() === [
                'public_hosts' => ['analytics.docs.test', 'metrics.docs.test'],
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['binding']['internal_host'])->toBe('analytics.orbit')
            ->and($decoded['success']['data']['binding']['public_hosts'])->toBe(['analytics.docs.test', 'metrics.docs.test'])
            ->and($decoded['success']['data']['binding']['tracking_paths'])->toBe(['/js/*', '/api/event']);
    });

    it('renders enable responses in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'app' => 'docs',
                'enabled' => true,
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => ['analytics.docs.test'],
                'tracking_paths' => ['/js/*', '/api/event'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:analytics enable', [
            'app' => 'docs',
            '--host' => ['analytics.docs.test'],
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('binding:')
            ->and($output)->toContain('analytics.docs.test');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'app:analytics enable', [
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
            'analytics.prerequisite_failed',
            'No active analytics backend is available.',
            ['app' => 'docs'],
        ), 422);

        [$exitCode, $output] = runCommand($this, 'app:analytics enable', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('analytics.prerequisite_failed')
            ->and($decoded['error']['meta']['app'])->toBe('docs');
    });
});
