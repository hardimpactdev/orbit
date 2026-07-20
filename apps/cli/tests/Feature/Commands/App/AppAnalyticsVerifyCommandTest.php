<?php

declare(strict_types=1);

use App\Services\Analytics\AnalyticsReadinessVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AppAnalyticsVerifyCommand', function (): void {
    it('returns caller-observed public readiness for every stored host', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'verification_context' => analyticsVerificationContext(),
        ]));
        app()->instance(AnalyticsReadinessVerifier::class, new FakeAnalyticsReadinessVerifier(
            analyticsVerificationResult(ready: true),
        ));

        [$exitCode, $output] = runCommand($this, 'instance:analytics verify', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/instances/docs/analytics/verify'
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['verification']['ready'])
            ->toBeTrue()
            ->and($decoded['success']['data']['verification']['hosts'][0]['event']['status'])
            ->toBe('not_run')
            ->and($decoded['success']['data']['verification']['hosts'][0]['plausible_site']['status'])
            ->toBe('unchecked');
    });

    it('returns diagnostic verification data and failure when public readiness is incomplete', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'verification_context' => analyticsVerificationContext(),
        ]));
        app()->instance(AnalyticsReadinessVerifier::class, new FakeAnalyticsReadinessVerifier(
            analyticsVerificationResult(ready: false),
        ));

        [$exitCode, $output] = runCommand($this, 'instance:analytics verify', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('analytics.public_not_ready')
            ->and($decoded['error']['meta']['instance'])
            ->toBe('docs')
            ->and($decoded['error']['data']['verification']['ready'])
            ->toBeFalse();
    });

    it('renders verification facts in human mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'verification_context' => analyticsVerificationContext(),
        ]));
        app()->instance(AnalyticsReadinessVerifier::class, new FakeAnalyticsReadinessVerifier(
            analyticsVerificationResult(ready: true),
        ));

        [$exitCode, $output] = runCommand($this, 'instance:analytics verify', ['instance' => 'docs']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Verifying Instance Analytics')
            ->and($output)
            ->toContain('route_intent: registered')
            ->and($output)
            ->toContain('script: ready (200)')
            ->and($output)
            ->toContain('dashboard: not_exposed (404)')
            ->and($output)
            ->toContain('event: not_run')
            ->and($output)
            ->toContain('plausible_site: unchecked');
    });

    it('requires an instance selector before gateway or public requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());
        app()->instance(AnalyticsReadinessVerifier::class, new FakeAnalyticsReadinessVerifier(
            analyticsVerificationResult(ready: true),
        ));

        [$exitCode, $output] = runCommand($this, 'instance:analytics verify', ['--json' => true]);

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

final readonly class FakeAnalyticsReadinessVerifier implements AnalyticsReadinessVerifier
{
    /** @param array<string, mixed> $result */
    public function __construct(
        private array $result,
    ) {}

    public function verify(array $context): array
    {
        return $this->result;
    }
}

/** @return array<string, mixed> */
function analyticsVerificationContext(): array
{
    return [
        'binding' => [
            'instance' => 'docs',
            'enabled' => true,
            'site_domain' => 'docs.test',
            'public_hosts' => ['analytics.docs.test'],
        ],
        'routes' => [['host' => 'analytics.docs.test', 'status' => 'registered']],
        'dns_expectation' => [
            'ingress_node' => 'edge-1',
            'targets' => [['type' => 'A', 'value' => '203.0.113.10']],
        ],
    ];
}

/** @return array<string, mixed> */
function analyticsVerificationResult(bool $ready): array
{
    return [
        'instance' => 'docs',
        'ready' => $ready,
        'hosts' => [[
            'host' => 'analytics.docs.test',
            'route_intent' => ['status' => 'registered'],
            'dns' => ['status' => $ready ? 'ready' : 'mismatch'],
            'tls' => ['status' => $ready ? 'ready' : 'unavailable'],
            'script' => [
                'status' => $ready ? 'ready' : 'unavailable',
                'url' => 'https://analytics.docs.test/js/script.js',
                'http_status' => $ready ? 200 : null,
            ],
            'dashboard' => [
                'status' => $ready ? 'not_exposed' : 'unverified',
                'url' => 'https://analytics.docs.test/',
                'http_status' => $ready ? 404 : null,
            ],
            'event' => ['status' => 'not_run'],
            'plausible_site' => [
                'status' => 'unchecked',
                'reason' => 'Plausible credentials are not managed by Orbit.',
            ],
            'ready' => $ready,
        ]],
    ];
}
