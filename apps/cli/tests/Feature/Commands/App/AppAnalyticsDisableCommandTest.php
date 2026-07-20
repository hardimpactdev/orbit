<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppAnalyticsDisableCommand', function (): void {
    it('requests app analytics disable through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'instance' => 'docs',
                'enabled' => false,
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => [],
                'tracking_paths' => ['/js/*', '/api/event'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:analytics disable', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/instances/docs/analytics/disable'
                && $request->data() === []
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['binding']['enabled'])
            ->toBeFalse()
            ->and($decoded['success']['data']['binding']['public_hosts'])
            ->toBe([]);
    });

    it('renders disable responses in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'instance' => 'docs',
                'enabled' => false,
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => [],
                'tracking_paths' => ['/js/*', '/api/event'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:analytics disable', [
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Disabling Instance Analytics')
            ->and($output)
            ->toContain('Remove ingress tracking routes')
            ->and($output)
            ->toContain('Remove router tracking routes')
            ->and($output)
            ->toContain("Analytics disabled for instance 'docs'")
            ->and($output)
            ->toContain('binding:')
            ->and($output)
            ->toContain('  instance: docs')
            ->and($output)
            ->toContain('  enabled: false')
            ->and($output)
            ->toContain('  internal_host: analytics.orbit')
            ->and($output)
            ->toContain('  public_hosts: []')
            ->and($output)
            ->not->toContain('{');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'instance:analytics disable', [
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
});
