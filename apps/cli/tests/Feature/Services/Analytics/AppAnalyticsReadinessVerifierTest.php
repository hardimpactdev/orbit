<?php

declare(strict_types=1);

use App\Services\Analytics\AppAnalyticsReadinessVerifier;
use App\Services\Analytics\HttpsProbe;
use App\Services\Analytics\PublicDnsResolver;

describe('AppAnalyticsReadinessVerifier', function (): void {
    it('reports direct public readiness without sending an event', function (): void {
        $dns = new FakePublicDnsResolver([
            'analytics.docs.test' => [['type' => 'A', 'value' => '93.184.216.34']],
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
            ->and($https->requests)
            ->toBe([
                ['url' => 'https://analytics.docs.test/js/script.js', 'addresses' => ['93.184.216.34']],
                ['url' => 'https://analytics.docs.test/', 'addresses' => ['93.184.216.34']],
            ]);
    });

    it('accepts a verified intermediary such as a proxied DNS provider', function (): void {
        $dns = new FakePublicDnsResolver([
            'analytics.docs.test' => [
                ['type' => 'A', 'value' => '104.16.132.229'],
                ['type' => 'AAAA', 'value' => '2606:4700::6810:84e5'],
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
            'analytics.docs.test' => [['type' => 'A', 'value' => '93.184.216.34']],
            'metrics.docs.test' => [['type' => 'A', 'value' => '93.184.216.34']],
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
            ->and(array_column($https->requests, 'url'))
            ->each->not->toContain('/api/event');
    });

    it('refuses private and reserved DNS answers without making an HTTPS request', function (): void {
        $dns = new FakePublicDnsResolver([
            'analytics.docs.test' => [
                ['type' => 'A', 'value' => '127.0.0.1'],
                ['type' => 'A', 'value' => '10.6.0.5'],
                ['type' => 'AAAA', 'value' => '::1'],
            ],
        ]);
        $https = new FakeHttpsProbe([]);

        $verification = new AppAnalyticsReadinessVerifier($dns, $https)->verify(readinessContext());

        expect($verification['ready'])
            ->toBeFalse()
            ->and($verification['hosts'][0]['dns']['status'])
            ->toBe('unsafe')
            ->and($verification['hosts'][0]['dns']['answers'])
            ->toBe([])
            ->and($https->requests)
            ->toBe([]);
    });

    it('pins HTTPS requests to the approved public DNS answers only', function (): void {
        $dns = new FakePublicDnsResolver([
            'analytics.docs.test' => [
                ['type' => 'A', 'value' => '10.6.0.5'],
                ['type' => 'A', 'value' => '93.184.216.34'],
            ],
        ]);
        $https = new FakeHttpsProbe([
            'https://analytics.docs.test/js/script.js' => successfulProbe(200),
            'https://analytics.docs.test/' => successfulProbe(404),
        ]);

        $verification = new AppAnalyticsReadinessVerifier($dns, $https)->verify(readinessContext());

        expect($verification['ready'])
            ->toBeTrue()
            ->and($https->requests)
            ->toBe([
                ['url' => 'https://analytics.docs.test/js/script.js', 'addresses' => ['93.184.216.34']],
                ['url' => 'https://analytics.docs.test/', 'addresses' => ['93.184.216.34']],
            ]);
    });

    it('refuses a single-label stored host before DNS or HTTPS probing', function (): void {
        $dns = new FakePublicDnsResolver(['localhost' => [['type' => 'A', 'value' => '93.184.216.34']]]);
        $https = new FakeHttpsProbe([]);
        $context = readinessContext();
        $context['binding']['public_hosts'] = ['localhost'];
        $context['routes'] = [['host' => 'localhost', 'status' => 'registered']];

        $verification = new AppAnalyticsReadinessVerifier($dns, $https)->verify($context);

        expect($verification['ready'])
            ->toBeFalse()
            ->and($verification['hosts'][0]['dns']['status'])
            ->toBe('unsafe')
            ->and($dns->resolvedHosts)
            ->toBe([])
            ->and($https->requests)
            ->toBe([]);
    });
});

final class FakePublicDnsResolver implements PublicDnsResolver
{
    /** @var list<string> */
    public array $resolvedHosts = [];

    /** @param array<string, list<array{type: string, value: string}>> $answers */
    public function __construct(
        private readonly array $answers,
    ) {}

    public function resolve(string $host): array
    {
        $this->resolvedHosts[] = $host;

        return $this->answers[$host] ?? [];
    }
}

final class FakeHttpsProbe implements HttpsProbe
{
    /** @var list<array{url: string, addresses: list<string>}> */
    public array $requests = [];

    /** @param array<string, array{completed: bool, http_status: int|null, tls_verified: bool, error: string|null}> $responses */
    public function __construct(
        private readonly array $responses,
    ) {}

    public function get(string $url, array $addresses): array
    {
        $this->requests[] = ['url' => $url, 'addresses' => $addresses];

        return $this->responses[$url] ?? failedProbe('No fake response configured.');
    }
}

/** @return array<string, mixed> */
function readinessContext(): array
{
    return [
        'binding' => [
            'app' => 'docs',
            'instance' => 'production',
            'enabled' => true,
            'site_domain' => 'docs.test',
            'public_hosts' => ['analytics.docs.test'],
        ],
        'routes' => [['host' => 'analytics.docs.test', 'status' => 'registered']],
        'dns_expectation' => [
            'targets' => [['type' => 'A', 'value' => '93.184.216.34']],
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
