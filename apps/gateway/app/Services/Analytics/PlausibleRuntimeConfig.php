<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\Processes\ProcessRuntime;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Support\Str;

final readonly class PlausibleRuntimeConfig
{
    public function __construct(
        private AnalyticsProcessEndpointResolver $endpointResolver,
    ) {}

    /** @param  array<string, mixed>  $runtimeConfig */
    public function for(
        NodeRoleAssignment $assignment,
        ?Process $existingProcess,
        array $runtimeConfig,
    ): PlausibleRuntimeSettings {
        $postgres = $this->endpointResolver->resolve($assignment, 'postgres_node_id', 'postgres');
        $clickHouse = $this->endpointResolver->resolve($assignment, 'clickhouse_node_id', 'clickhouse');
        $postgresCredentials = $this->endpointResolver->credentials($postgres['process'], 'PostgreSQL');
        $clickHouseCredentials = $this->endpointResolver->credentials($clickHouse['process'], 'ClickHouse');
        $environment = $this->stringKeyedArray($runtimeConfig['environment'] ?? null);
        $existingCredentials = $this->stringKeyedArray($existingProcess?->credentials);
        $existingEnvironment = $this->stringKeyedArray($existingCredentials['environment'] ?? null);

        $secretEnvironment = [
            'DATABASE_URL' => sprintf(
                'postgres://%s:%s@%s:%d/%s',
                rawurlencode($postgresCredentials['username']),
                rawurlencode($postgresCredentials['password']),
                $postgres['host'],
                $postgres['port'],
                rawurlencode($postgresCredentials['database']),
            ),
            'CLICKHOUSE_DATABASE_URL' => sprintf(
                'http://%s:%s@%s:%d/%s',
                rawurlencode($clickHouseCredentials['username']),
                rawurlencode($clickHouseCredentials['password']),
                $clickHouse['host'],
                $clickHouse['port'],
                rawurlencode($clickHouseCredentials['database']),
            ),
            'SECRET_KEY_BASE' => $this->secretKeyBase($existingEnvironment),
        ];
        $runtimeConfig['environment'] = $environment;
        $runtimeConfig['credential_hash'] = substr(
            string: hash('sha256', json_encode($secretEnvironment, JSON_THROW_ON_ERROR)),
            offset: 0,
            length: 16,
        );

        return new PlausibleRuntimeSettings(
            runtimeConfig: $this->withRefreshedSpecHash($runtimeConfig),
            credentials: ['environment' => $secretEnvironment],
        );
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function secretKeyBase(array $environment): string
    {
        $secret = $environment['SECRET_KEY_BASE'] ?? null;

        if (is_string($secret) && strlen($secret) >= 64) {
            return $secret;
        }

        return Str::random(64);
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array<string, mixed>
     */
    private function withRefreshedSpecHash(array $runtimeConfig): array
    {
        unset($runtimeConfig['spec_hash'], $runtimeConfig['labels']);

        $spec = [
            ...$runtimeConfig,
            'runtime' => ProcessRuntime::Docker->value,
            'process' => 'plausible',
        ];
        ksort($spec);
        $specHash = substr(
            string: hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)),
            offset: 0,
            length: 16,
        );

        $runtimeConfig['spec_hash'] = $specHash;
        $runtimeConfig['labels'] = [
            'orbit.managed' => 'true',
            'orbit.process' => 'plausible',
            'orbit.process.service' => 'plausible',
            'orbit.process.version_family' => (string) ($runtimeConfig['version_family'] ?? ''),
            'orbit.process.version' => (string) ($runtimeConfig['version'] ?? ''),
            'orbit.process.spec_hash' => $specHash,
        ];

        return $runtimeConfig;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
