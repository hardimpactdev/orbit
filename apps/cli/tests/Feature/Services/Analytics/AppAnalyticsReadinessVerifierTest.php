<?php

declare(strict_types=1);

use App\Services\Analytics\AppAnalyticsReadinessVerifier;
use App\Services\Analytics\HttpsProbe;
use App\Services\Analytics\PublicDnsResolver;

describe('AppAnalyticsReadinessVerifier', function (): void {
    it('reports direct public readiness without sending an event', function (): void {
        $dns = new FakePublicDnsResolver([
            'analytics.docs.test' => [['type' => 'A', 'value' => '203.0.113.10']],
        ]);
        $https = new FakeHttpsProbe([
            'https://analytics.docs.test/js/script.js' => successfulProbe(200),
            'https://analytics.docs.test/' => successfulProbe(404),
        ]);

        $verification = new AppAnalyticsReadinessVerifier($dns, $https)->verify(readinessContext());

        expect($verification['ready'])
            ->toBeTrue()
            ->and($verification['hosts'][0]['dns']['routing'])
            ->toBe('direct')
            ->and($verification['hosts'][0]['dns']['matches_ingress'])
            ->toBeTrue()
            ->and($verification['hosts'][0]['event']['status'])
            ->toBe('not_run')
            ->and($verification['hosts'][0]['plausible_site']['status'])
            ->toBe('unchecked')
            ->and($https->requestedUrls)
            ->toBe([
                'https://analytics.docs.test/js/script.js',
                'https://analytics.docs.test/',
            ]);
    });

    it('accepts a verified intermediary such as a proxied DNS provider', function (): void {
        $dns = new FakePublicDnsResolver([
            'analytics.docs.test' => [
                ['type' => 'A', 'value' => '198.51.100.20'],
                ['type' => 'AAAA', 'value' => '2001:db8::20'],
            ],
        ]);
        $https = new FakeHttpsProbe([
            'https://analytics.docs.test/js/script.js' => successfulProbe(200),
            'https://analytics.docs.test/' => successfulProbe(404),
        ]);

        $verification = new AppAnalyticsReadinessVerifier($dns, $https)->verify(readinessContext());

        expect($verification['ready'])
            ->toBeTrue()
            ->and($verification['hosts'][0]['dns']['routing'])
            ->toBe('intermediary')
            ->and($verification['hosts'][0]['dns']['matches_ingress'])
            ->toBeFalse();
    });

    it('reports incomplete readiness when public behavior is unavailable', function (): void {
        $dns = new FakePublicDnsResolver(['analytics.docs.test' => []]);
        $https = new FakeHttpsProbe([
            'https://analytics.docs.test/js/script.js' => failedProbe('Could not resolve host.'),
            'https://analytics.docs.test/' => failedProbe('Could not resolve host.'),
        ]);

        $verification = new AppAnalyticsReadinessVerifier($dns, $https)->verify(readinessContext());

        expect($verification['ready'])
            ->toBeFalse()
            ->and($verification['hosts'][0]['dns']['status'])
            ->toBe('unresolved')
            ->and($verification['hosts'][0]['tls']['status'])
            ->toBe('unavailable')
            ->and($verification['hosts'][0]['script']['status'])
            ->toBe('unavailable')
            ->and($verification['hosts'][0]['dashboard']['status'])
            ->toBe('unverified');
    });

    it('requires every configured analytics host to be ready', function (): void {
        $dns = new FakePublicDnsResolver([
            'analytics.docs.test' => [['type' => 'A', 'value' => '203.0.113.10']],
            'metrics.docs.test' => [['type' => 'A', 'value' => '203.0.113.10']],
        ]);
        $https = new FakeHttpsProbe([
            'https://analytics.docs.test/js/script.js' => successfulProbe(200),
            'https://analytics.docs.test/' => successfulProbe(404),
            'https://metrics.docs.test/js/script.js' => successfulProbe(503),
            'https://metrics.docs.test/' => successfulProbe(404),
        ]);
        $context = readinessContext();
        $context['binding']['public_hosts'][] = 'metrics.docs.test';
        $context['routes'][] = ['host' => 'metrics.docs.test', 'status' => 'registered'];

        $verification = new AppAnalyticsReadinessVerifier($dns, $https)->verify($context);

        expect($verification['ready'])
            ->toBeFalse()
            ->and($verification['hosts'])
            ->toHaveCount(2)
            ->and($verification['hosts'][1]['ready'])
            ->toBeFalse()
            ->and($https->requestedUrls)
            ->each->not->toContain('/api/event');
    });
});

final class FakePublicDnsResolver implements PublicDnsResolver
{
    /** @param array<string, list<array{type: string, value: string}>> $answers */
    public function __construct(
        private readonly array $answers,
    ) {}

    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? [];
    }
}

final class FakeHttpsProbe implements HttpsProbe
{
    /** @var list<string> */
    public array $requestedUrls = [];

    /** @param array<string, array{completed: bool, http_status: int|null, tls_verified: bool, error: string|null}> $responses */
    public function __construct(
        private readonly array $responses,
    ) {}

    public function get(string $url): array
    {
        $this->requestedUrls[] = $url;

        return $this->responses[$url] ?? failedProbe('No fake response configured.');
    }
}

/** @return array<string, mixed> */
function readinessContext(): array
{
    return [
        'binding' => [
            'app' => 'docs',
            'enabled' => true,
            'site_domain' => 'docs.test',
            'public_hosts' => ['analytics.docs.test'],
        ],
        'routes' => [['host' => 'analytics.docs.test', 'status' => 'registered']],
        'dns_expectation' => [
            'targets' => [['type' => 'A', 'value' => '203.0.113.10']],
        ],
    ];
}

/** @return array{completed: true, http_status: int, tls_verified: true, error: null} */
function successfulProbe(int $status): array
{
    return [
        'completed' => true,
        'http_status' => $status,
        'tls_verified' => true,
        'error' => null,
    ];
}

/** @return array{completed: false, http_status: null, tls_verified: false, error: string} */
function failedProbe(string $message): array
{
    return [
        'completed' => false,
        'http_status' => null,
        'tls_verified' => false,
        'error' => $message,
    ];
}
