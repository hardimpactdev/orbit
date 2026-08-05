<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class AppAnalyticsReadinessVerifier implements AnalyticsReadinessVerifier
{
    public function __construct(
        private PublicDnsResolver $dns,
        private HttpsProbe $https,
    ) {}

    public function verify(array $context): array
    {
        $binding = $this->arrayField($context, 'binding');
        $app = $this->stringField($binding, 'app');
        $instance = $this->stringField($binding, 'instance');
        $enabled = ($binding['enabled'] ?? false) === true;
        $hosts = $this->stringList($binding['public_hosts'] ?? []);
        $routes = $this->routesByHost($this->arrayList($context['routes'] ?? []));
        $dnsExpectation = $this->arrayField($context, 'dns_expectation');
        $expectedTargets = $this->addressList($dnsExpectation['targets'] ?? []);
        $results = [];

        foreach ($hosts as $host) {
            $results[] = $this->verifyHost($host, $routes[$host] ?? 'unverifiable', $expectedTargets);
        }

        return [
            'app' => $app,
            'instance' => $instance,
            'ready' => $enabled
                && $results !== []
                && ! in_array(
                    false,
                    array_column($results, 'ready'),
                    strict: true,
                ),
            'hosts' => $results,
        ];
    }

    /**
     * @param  list<array{type: string, value: string}>  $expectedTargets
     * @return array<string, mixed>
     */
    private function verifyHost(string $host, string $routeStatus, array $expectedTargets): array
    {
        $hostIsSafe = $this->isPublicHostname($host);
        $resolvedAnswers = $hostIsSafe ? $this->addressList($this->dns->resolve($host)) : [];
        $answers = array_values(array_filter(
            $resolvedAnswers,
            fn (array $answer): bool => $this->isPublicIp($answer['value']),
        ));
        $matchesIngress = $answers !== [] && $this->addressValues($answers) === $this->addressValues($expectedTargets);
        $scriptUrl = "https://{$host}/js/script.js";
        $dashboardUrl = "https://{$host}/";
        $approvedAddresses = array_column($answers, 'value');
        $scriptProbe = $answers === []
            ? $this->skippedProbe()
            : $this->https->get($scriptUrl, $approvedAddresses);
        $dashboardProbe = $answers === []
            ? $this->skippedProbe()
            : $this->https->get($dashboardUrl, $approvedAddresses);
        $dnsReady = $answers !== [];
        $dnsStatus = match (true) {
            ! $hostIsSafe, $resolvedAnswers !== [] && ! $dnsReady => 'unsafe',
            $dnsReady => 'ready',
            default => 'unresolved',
        };
        $tlsReady = $this->tlsVerified($scriptProbe) && $this->tlsVerified($dashboardProbe);
        $scriptReady = ($scriptProbe['http_status'] ?? null) === 200;
        $dashboardPrivate = ($dashboardProbe['http_status'] ?? null) === 404;
        $ready = $routeStatus === 'registered' && $dnsReady && $tlsReady && $scriptReady && $dashboardPrivate;

        return [
            'host' => $host,
            'route_intent' => ['status' => $routeStatus],
            'dns' => [
                'status' => $dnsStatus,
                'routing' => match (true) {
                    ! $dnsReady => 'unknown',
                    $matchesIngress => 'direct',
                    default => 'intermediary',
                },
                'answers' => $answers,
                'expected_targets' => $expectedTargets,
                'matches_ingress' => $matchesIngress,
            ],
            'tls' => [
                'status' => $tlsReady ? 'ready' : 'unavailable',
                'verified' => $tlsReady,
            ],
            'script' => [
                'status' => $scriptReady ? 'ready' : 'unavailable',
                'url' => $scriptUrl,
                'http_status' => $this->nullableInteger($scriptProbe['http_status'] ?? null),
                'error' => $this->nullableString($scriptProbe['error'] ?? null),
            ],
            'dashboard' => [
                'status' => $dashboardPrivate ? 'not_exposed' : 'unverified',
                'url' => $dashboardUrl,
                'http_status' => $this->nullableInteger($dashboardProbe['http_status'] ?? null),
                'error' => $this->nullableString($dashboardProbe['error'] ?? null),
            ],
            'event' => ['status' => 'not_run'],
            'plausible_site' => [
                'status' => 'unchecked',
                'reason' => 'Plausible credentials are not managed by Orbit.',
            ],
            'ready' => $ready,
        ];
    }

    /** @return array{completed: false, http_status: null, tls_verified: false, error: string} */
    private function skippedProbe(): array
    {
        return [
            'completed' => false,
            'http_status' => null,
            'tls_verified' => false,
            'error' => 'HTTPS probe skipped because DNS did not return an approved public address.',
        ];
    }

    private function isPublicHostname(string $host): bool
    {
        return (
            str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        );
    }

    private function isPublicIp(string $value): bool
    {
        return GlobalUnicastIpAddress::isGlobalUnicast($value);
    }

    /** @param array<array-key, mixed> $probe */
    private function tlsVerified(array $probe): bool
    {
        return ($probe['completed'] ?? false) === true && ($probe['tls_verified'] ?? false) === true;
    }

    /**
     * @param  list<array<array-key, mixed>>  $routes
     * @return array<string, string>
     */
    private function routesByHost(array $routes): array
    {
        $byHost = [];

        foreach ($routes as $route) {
            $host = $this->stringField($route, 'host');
            $status = $this->stringField($route, 'status');

            if ($host !== '' && $status !== '') {
                $byHost[$host] = $status;
            }
        }

        return $byHost;
    }

    /** @return list<array<array-key, mixed>> */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }

    /** @return list<array{type: string, value: string}> */
    private function addressList(mixed $value): array
    {
        $addresses = [];

        foreach ($this->arrayList($value) as $address) {
            $type = $this->stringField($address, 'type');
            $ip = $this->normalizedIp($this->stringField($address, 'value'));

            if (! in_array($type, ['A', 'AAAA'], strict: true) || $ip === null) {
                continue;
            }

            $candidate = ['type' => $type, 'value' => $ip];

            if (! in_array($candidate, $addresses, strict: true)) {
                $addresses[] = $candidate;
            }
        }

        usort(
            $addresses,
            static fn (array $left, array $right): int => (
                [$left['type'], $left['value']] <=> [$right['type'], $right['value']]
            ),
        );

        return $addresses;
    }

    /**
     * @param  list<array{type: string, value: string}>  $addresses
     * @return list<string>
     */
    private function addressValues(array $addresses): array
    {
        $values = array_map(
            static fn (array $address): string => "{$address['type']}:{$address['value']}",
            $addresses,
        );
        sort($values);

        return $values;
    }

    private function normalizedIp(string $value): ?string
    {
        $packed = inet_pton($value);

        if ($packed === false) {
            return null;
        }

        $normalized = inet_ntop($packed);

        return is_string($normalized) ? $normalized : null;
    }

    /** @param array<array-key, mixed> $array */
    private function arrayField(array $array, string $key): array
    {
        $value = $array[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @param array<array-key, mixed> $array */
    private function stringField(array $array, string $key): string
    {
        $value = $array[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
