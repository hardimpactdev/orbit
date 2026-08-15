<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Exceptions\AnalyticsDomainRequired;
use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\Instance;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AnalyticsPublicHostNormalizer
{
    public const int MAXIMUM_HOSTS = 10;

    /**
     * @param  array<int, mixed>  $publicHosts
     * @return list<string>
     */
    public function normalize(Instance $instance, array $publicHosts): array
    {
        $domain = $this->publicDomain($instance);
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

    private function publicDomain(Instance $instance): string
    {
        $instance->loadMissing('app');
        $config = $instance->driver_config;
        $domain = match (true) {
            $config instanceof OrbitInstanceDriverConfigData => trim((string) $config->domain),
            $config instanceof LaravelCloudInstanceDriverConfigData => trim((string) $config->domain),
            default => '',
        };

        if (! $this->isPublicDnsHostname($domain)) {
            throw new AnalyticsDomainRequired(
                "Instance '{$instance->app->name}.{$instance->name}' requires a configured valid public domain before analytics can be enabled.",
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
