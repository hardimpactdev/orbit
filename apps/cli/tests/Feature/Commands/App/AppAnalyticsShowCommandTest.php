<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppAnalyticsShowCommand', function (): void {
    it('requests app analytics binding state from the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'instance' => 'docs',
                'enabled' => true,
                'site_domain' => 'docs.test',
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => ['analytics.docs.test'],
                'tracking_paths' => ['/js/*', '/api/event'],
                'tracking_endpoints' => [[
                    'host' => 'analytics.docs.test',
                    'script_base_url' => 'https://analytics.docs.test',
                    'script_url' => 'https://analytics.docs.test/js/script.js',
                    'event_endpoint' => 'https://analytics.docs.test/api/event',
                    'data_domain' => 'docs.test',
                    'snippet' => '<script defer data-domain="docs.test" src="https://analytics.docs.test/js/script.js"></script>',
                ]],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:analytics show', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/instances/docs/analytics'
                && $request->data() === []
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['binding']['internal_host'])
            ->toBe('analytics.orbit')
            ->and($decoded['success']['data']['binding']['public_hosts'])
            ->toBe(['analytics.docs.test']);
    });

    it('renders show responses in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'instance' => 'docs',
                'enabled' => true,
                'site_domain' => 'docs.test',
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => ['analytics.docs.test'],
                'tracking_paths' => ['/js/*', '/api/event'],
                'tracking_endpoints' => [[
                    'host' => 'analytics.docs.test',
                    'script_base_url' => 'https://analytics.docs.test',
                    'script_url' => 'https://analytics.docs.test/js/script.js',
                    'event_endpoint' => 'https://analytics.docs.test/api/event',
                    'data_domain' => 'docs.test',
                    'snippet' => '<script defer data-domain="docs.test" src="https://analytics.docs.test/js/script.js"></script>',
                ]],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:analytics show', [
            'instance' => 'docs',
        ]);

        $expectedBinding = implode(PHP_EOL, [
            'binding:',
            '  instance: docs',
            '  enabled: true',
            '  site_domain: docs.test',
            '  internal_host: analytics.orbit',
            '  dashboard_url: https://analytics.orbit',
            '  public_hosts:',
            '    - analytics.docs.test',
            '  tracking_endpoints:',
            '    - host: analytics.docs.test',
            '      script_base_url: https://analytics.docs.test',
            '      script_url: https://analytics.docs.test/js/script.js',
            '      event_endpoint: https://analytics.docs.test/api/event',
            '      data_domain: docs.test',
            '      snippet: <script defer data-domain="docs.test" src="https://analytics.docs.test/js/script.js"></script>',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain($expectedBinding)
            ->and($output)
            ->not->toContain('{');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'instance:analytics show', [
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
