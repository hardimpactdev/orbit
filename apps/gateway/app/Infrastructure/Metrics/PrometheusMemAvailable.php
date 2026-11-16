<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Throwable;

/**
 * Reads each Node's available memory (Linux `MemAvailable`) from the node exporter series in the Metrics role's Prometheus, in one query
 * for the whole fleet. proof:run reads it before it places copies (docs/cli/proof/run.mdx#the-run). Nothing is cached: a proof reads it
 * at most once each admission attempt.
 */
final readonly class PrometheusMemAvailable
{
    private const string Query = 'node_memory_MemAvailable_bytes{job="orbit-node-exporter"}';

    public function __construct(private GrafanaPrometheusClient $prometheus) {}

    /**
     * The available bytes of each Node that reported them, keyed by the Node name of the scrape, or null when Prometheus did not answer.
     *
     * @return array<string, int>|null
     */
    public function bytes(): ?array
    {
        try {
            $data = $this->prometheus->query(self::Query)['data'] ?? null;
        } catch (Throwable) {
            return null;
        }
        $series = is_array($data) ? ($data['result'] ?? null) : null;
        if (! is_array($series)) {
            return null;
        }

        $bytes = [];
        foreach ($series as $entry) {
            $metric = is_array($entry) ? ($entry['metric'] ?? null) : null;
            $sample = is_array($entry) ? ($entry['value'] ?? null) : null;
            $value = is_array($sample) ? ($sample[1] ?? null) : null;
            $node = is_array($metric) ? ($metric['node'] ?? null) : null;
            if (is_string($node) && is_numeric($value)) {
                $bytes[$node] = (int) $value;
            }
        }

        return $bytes;
    }
}
