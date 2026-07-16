<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\Processes\ProcessRuntime;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Support\Str;

final class PlausibleRuntimeConfig
{
    public function __construct(
        private readonly AnalyticsProcessEndpointResolver $endpointResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array<string, mixed>
     */
    public function for(
        NodeRoleAssignment $assignment,
        ?Process $existingProcess,
        array $runtimeConfig,
    ): array {
        $postgres = $this->endpointResolver->resolve($assignment, 'postgres_node_id', 'postgres');
        $clickHouse = $this->endpointResolver->resolve($assignment, 'clickhouse_node_id', 'clickhouse');
        $credentials = $this->endpointResolver->postgresCredentials($postgres['process']);
        $environment = (array) ($runtimeConfig['environment'] ?? []);
        $existingEnvironment = (array) ($existingProcess?->runtime_config['environment'] ?? []);

        $runtimeConfig['environment'] = [
            ...$environment,
            'DATABASE_URL' => sprintf(
                'postgres://%s:%s@%s:%d/plausible',
                rawurlencode($credentials['username']),
                rawurlencode($credentials['password']),
                $postgres['host'],
                $postgres['port'],
            ),
            'CLICKHOUSE_DATABASE_URL' => "http://{$clickHouse['host']}:{$clickHouse['port']}/plausible",
            'SECRET_KEY_BASE' => $existingEnvironment['SECRET_KEY_BASE'] ?? Str::random(64),
        ];

        return $this->withRefreshedSpecHash($runtimeConfig);
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
            'runtime' => ProcessRuntime::DockerSwarm->value,
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
}
