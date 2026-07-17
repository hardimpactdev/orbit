<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Exceptions\AnalyticsDomainRequired;
use App\Models\App;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AnalyticsPublicHostNormalizer
{
    /**
     * @param  array<int, mixed>  $publicHosts
     * @return list<string>
     */
    public function normalize(App $app, array $publicHosts): array
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

            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new InvalidArgumentException('Analytics public hosts must be valid hostnames.');
            }

            if (! in_array(needle: $host, haystack: $hosts, strict: true)) {
                $hosts[] = $host;
            }
        }

        return $hosts === [] ? ["analytics.{$domain}"] : $hosts;
    }

    private function publicDomain(App $app): string
    {
        $domain = is_string($app->domain) ? trim($app->domain) : '';

        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new AnalyticsDomainRequired(
                "App '{$app->name}' requires a configured valid public domain before analytics can be enabled.",
            );
        }

        return Str::lower($domain);
    }
}
