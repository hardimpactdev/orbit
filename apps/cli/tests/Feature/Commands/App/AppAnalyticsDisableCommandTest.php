<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppAnalyticsDisableCommand', function (): void {
    it('requests app analytics disable through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'app' => 'docs',
                'enabled' => false,
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => [],
                'tracking_paths' => ['/js/*', '/api/event'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:analytics disable', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/docs/analytics/disable'
            && $request->data() === []);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['binding']['enabled'])->toBeFalse()
            ->and($decoded['success']['data']['binding']['public_hosts'])->toBe([]);
    });

    it('renders disable responses in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'app' => 'docs',
                'enabled' => false,
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => [],
                'tracking_paths' => ['/js/*', '/api/event'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:analytics disable', [
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('binding:')
            ->and($output)->toContain('analytics.orbit');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'app:analytics disable', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });
});
