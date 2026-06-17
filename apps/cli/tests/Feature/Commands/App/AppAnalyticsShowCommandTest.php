<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppAnalyticsShowCommand', function (): void {
    it('requests app analytics binding state from the gateway', function (): void {
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

        [$exitCode, $output] = runCommand($this, 'app:analytics show', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/apps/docs/analytics'
            && $request->data() === []);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['binding']['internal_host'])->toBe('analytics.orbit')
            ->and($decoded['success']['data']['binding']['public_hosts'])->toBe(['analytics.docs.test']);
    });

    it('renders show responses in human mode', function (): void {
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

        [$exitCode, $output] = runCommand($this, 'app:analytics show', [
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('binding:')
            ->and($output)->toContain('analytics.docs.test');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'app:analytics show', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });
});
