<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads the runner state that each CI Node's `orbit-ci-runner-state` timer writes as a node exporter textfile
 * (docs/reference/node-provisioning.md#runner-state-for-the-runner-view), from the Metrics role's Prometheus, in one query for the
 * whole fleet. The answer serves every caller for {@see self::CacheSeconds}; the scrapes it reads are no fresher. A failed query is not
 * kept, so the next caller asks again.
 *
 * @phpstan-type CiRunnerState array{state: string, service: string, version: ?string, busy: bool}
 * @phpstan-type CiNodeRunnerStates array{observed_at: ?int, read_ok: bool, runners: array<string, CiRunnerState>}
 */
final readonly class PrometheusCiRunnerStates
{
    public const int CacheSeconds = 10;

    private const string CacheKey = 'orbit:ci-runners:states';

    private const string Query = '{job="orbit-node-exporter",__name__=~"orbit_ci_runner_info|orbit_ci_runner_busy'
        .'|orbit_ci_runners_observed_timestamp_seconds|orbit_ci_runners_read_ok"}';

    public function __construct(private GrafanaPrometheusClient $prometheus) {}

    /**
     * The runner state of each Node that reported one, keyed by the Node name of the scrape, or null when Prometheus did not answer.
     *
     * @return array<string, CiNodeRunnerStates>|null
     */
    public function states(): ?array
    {
        $series = Cache::get(self::CacheKey);

        if (! is_array($series)) {
            try {
                $data = $this->prometheus->query(self::Query)['data'] ?? null;
                $series = is_array($data) ? ($data['result'] ?? null) : null;
            } catch (Throwable) {
                return null;
            }

            if (! is_array($series)) {
                return null;
            }

            Cache::put(self::CacheKey, $series, self::CacheSeconds);
        }

        return self::parse($series);
    }

    /**
     * @param  array<array-key, mixed>  $series
     * @return array<string, CiNodeRunnerStates>
     */
    private static function parse(array $series): array
    {
        $nodes = [];
        $busy = [];

        foreach ($series as $entry) {
            $metric = is_array($entry) ? ($entry['metric'] ?? null) : null;
            $sample = is_array($entry) ? ($entry['value'] ?? null) : null;
            $value = is_array($sample) ? ($sample[1] ?? null) : null;
            $node = is_array($metric) ? ($metric['node'] ?? null) : null;
            if (! is_array($metric) || ! is_string($node) || ! is_numeric($value)) {
                continue;
            }

            $nodes[$node] ??= ['observed_at' => null, 'read_ok' => false, 'runners' => []];
            $name = $metric['__name__'] ?? null;
            $runner = $metric['runner'] ?? null;
            $state = $metric['state'] ?? null;
            $service = $metric['service'] ?? null;
            $version = $metric['version'] ?? null;

            if ($name === 'orbit_ci_runners_observed_timestamp_seconds') {
                $nodes[$node]['observed_at'] = (int) $value;
            } elseif ($name === 'orbit_ci_runners_read_ok') {
                $nodes[$node]['read_ok'] = (int) $value === 1;
            } elseif ($name === 'orbit_ci_runner_busy' && is_string($runner)) {
                $busy[$node][$runner] = (int) $value === 1;
            } elseif ($name === 'orbit_ci_runner_info' && is_string($runner) && is_string($state) && is_string($service)) {
                $nodes[$node]['runners'][$runner] = ['state' => $state, 'service' => $service,
                    'version' => is_string($version) && $version !== '' ? $version : null, 'busy' => false];
            }
        }

        foreach ($nodes as $node => $state) {
            $runners = [];
            foreach ($state['runners'] as $runner => $found) {
                $runners[$runner] = [...$found, 'busy' => $busy[$node][$runner] ?? false];
            }
            ksort($runners);
            $nodes[$node]['runners'] = $runners;
        }

        return $nodes;
    }
}
