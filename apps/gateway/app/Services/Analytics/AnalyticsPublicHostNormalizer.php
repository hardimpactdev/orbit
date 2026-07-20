<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Exceptions\AnalyticsDomainRequired;
use App\Models\Project;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AnalyticsPublicHostNormalizer
{
    public const int MAXIMUM_HOSTS = 10;

    /**
     * @param  array<int, mixed>  $publicHosts
     * @return list<string>
     */
    public function normalize(Project $app, array $publicHosts): array
    {
        $domain = $this->publicDomain($app);
        $hosts = [];

        foreach ($publicHosts as $publicHost) {
            if (! is_string($publicHost)) {
                throw new InvalidArgumentException('Analytics public hosts must be strings.');
            }

            $host = Str::lower(trim($publicHost));

            if ($host === '') {
                continue;
            }

            if (str_contains($host, '://')) {
                throw new InvalidArgumentException('Analytics public hosts must be hostnames, not URLs.');
            }

            if (! $this->isPublicDnsHostname($host)) {
                throw new InvalidArgumentException('Analytics public hosts must be public DNS hostnames.');
            }

            if (! in_array(needle: $host, haystack: $hosts, strict: true)) {
                $hosts[] = $host;

                if (count($hosts) > self::MAXIMUM_HOSTS) {
                    throw new InvalidArgumentException(
                        'Analytics supports at most '.self::MAXIMUM_HOSTS.' public hosts.',
                    );
                }
            }
        }

        return $hosts === [] ? ["analytics.{$domain}"] : $hosts;
    }

    private function publicDomain(Project $app): string
    {
        $domain = is_string($app->domain) ? trim($app->domain) : '';

        if (! $this->isPublicDnsHostname($domain)) {
            throw new AnalyticsDomainRequired(
                "App '{$app->name}' requires a configured valid public domain before analytics can be enabled.",
            );
        }

        return Str::lower($domain);
    }

    private function isPublicDnsHostname(string $host): bool
    {
        return (
            str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        );
    }
}
