<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppAnalyticsEnableCommand', function (): void {
    it('forwards enable payloads to the gateway app analytics endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'binding' => [
                'instance' => 'docs',
                'enabled' => true,
                'site_domain' => 'docs.test',
                'internal_host' => 'analytics.orbit',
                'dashboard_url' => 'https://analytics.orbit',
                'public_hosts' => ['analytics.docs.test', 'metrics.docs.test'],
                'tracking_paths' => ['/js/*', '/api/event'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:analytics enable', [
            'instance' => 'docs',
            '--host' => ['analytics.docs.test', 'metrics.docs.test'],
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/instances/docs/analytics/enable'
                && $request->data() === [
                    'public_hosts' => ['analytics.docs.test', 'metrics.docs.test'],
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['binding']['internal_host'])
            ->toBe('analytics.orbit')
            ->and($decoded['success']['data']['binding']['public_hosts'])
            ->toBe(['analytics.docs.test', 'metrics.docs.test'])
            ->and($decoded['success']['data']['binding']['tracking_paths'])
            ->toBe(['/js/*', '/api/event']);
    });

    it('renders enable responses in human mode', function (): void {
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
            'route_enactment' => ['status' => 'completed', 'placements' => ['router', 'ingress']],
            'dns_expectation' => [
                'hosts' => ['analytics.docs.test'],
                'ingress_node' => 'edge-1',
                'targets' => [['type' => 'A', 'value' => '203.0.113.10']],
                'provider_managed' => false,
            ],
            'public_readiness' => ['status' => 'not_verified'],
            'remaining_actions' => ['configure_provider_dns', 'verify_public_readiness'],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:analytics enable', [
            'instance' => 'docs',
            '--host' => ['analytics.docs.test'],
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
            'route_enactment:',
            '  status: completed',
            '  placements:',
            '    - router',
            '    - ingress',
            'dns_expectation:',
            '  ingress_node: edge-1',
            '  targets:',
            '    - type: A',
            '      value: 203.0.113.10',
            '  provider_managed: false',
            'public_readiness:',
            '  status: not_verified',
            'remaining_actions:',
            '  - configure_provider_dns',
            '  - verify_public_readiness',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Enabling Instance Analytics')
            ->and($output)
            ->toContain('Apply ingress TLS and tracking routes')
            ->and($output)
            ->toContain("Analytics enabled for instance 'docs'")
            ->and($output)
            ->toContain($expectedBinding)
            ->and($output)
            ->not->toContain('{');
    });

    it('requires an app selector before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'instance:analytics enable', [
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

    it('maps gateway failures into canonical CLI failures', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'analytics.prerequisite_failed',
            'No active analytics backend is available.',
            ['instance' => 'docs'],
        ), 422);

        [$exitCode, $output] = runCommand($this, 'instance:analytics enable', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('analytics.prerequisite_failed')
            ->and($decoded['error']['meta']['instance'])
            ->toBe('docs');
    });
});
