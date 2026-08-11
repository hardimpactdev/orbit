<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\Process;
use App\Services\Analytics\AnalyticsDatabaseResolver;
use App\Services\Support\GatewayActionResult;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Orbit\Core\Nodes\NodeTld;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class WorkloadNodeCreationResolver
{
    public function __construct(
        private AnalyticsDatabaseResolver $analyticsDatabaseResolver,
    ) {}

    /**
     * @param  list<string>  $roles
     */
    public function resolve(
        array $roles,
        NodeCreationInput $input,
        bool $requireObservedPlatform,
    ): WorkloadNodeCreationRequest|GatewayActionResult {
        $inputs = $this->resolveInputs($roles, $requireObservedPlatform, $input);

        if ($inputs instanceof GatewayActionResult) {
            return $inputs;
        }

        $placement = $this->resolveIngressPlacement($roles, $input);

        if ($placement instanceof GatewayActionResult) {
            return $placement;
        }

        return new WorkloadNodeCreationRequest(
            roles: $placement->roles,
            inputs: $inputs,
            ingressNodeId: $placement->ingressNodeId,
        );
    }

    /**
     * @param  list<string>  $roles
     */
    private function resolveInputs(
        array $roles,
        bool $requireObservedPlatform,
        NodeCreationInput $input,
    ): WorkloadNodeProvisioningInput|GatewayActionResult {
        $needsHost = array_intersect($roles, [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
            NodeRoleName::Database->value,
            NodeRoleName::Ingress->value,
            NodeRoleName::Agent->value,
            NodeRoleName::Metrics->value,
            NodeRoleName::Analytics->value,
            NodeRoleName::S3->value,
        ]) !== [];

        if (! $needsHost && $input->stringOption('host') !== null) {
            return $this->validationFailed(
                'host',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use host provisioning.',
            );
        }

        if (! $needsHost && $input->stringOption('host-key-fingerprint') !== null) {
            return $this->validationFailed(
                'host_key_fingerprint',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use host-key fingerprint pinning.',
            );
        }

        if (! $needsHost && $input->stringOption('gateway-endpoint') !== null) {
            return $this->validationFailed(
                'gateway_endpoint',
                'Only app-dev, app-prod, database, ingress, agent, s3, metrics, analytics, and gateway use WireGuard endpoint overrides.',
            );
        }

        $host = $needsHost ? $input->stringOption('host') : null;

        if ($needsHost && $host === null) {
            return $this->validationFailed('host', 'Host is required for workload roles that provision a host.');
        }

        if ($host !== null && ! $this->isValidHost($host)) {
            return $this->validationFailed('host', 'Host must be a valid IP address or dotted DNS name.');
        }

        $gatewayEndpoint = $input->stringOption('gateway-endpoint');

        if ($gatewayEndpoint !== null && ! $this->isValidHost($gatewayEndpoint)) {
            return $this->validationFailed(
                'gateway_endpoint',
                'Gateway endpoint must be a valid IP address or dotted DNS name.',
            );
        }

        $platform = $input->stringOption('platform');
        $architecture = $input->stringOption('architecture');

        if ($requireObservedPlatform && $platform === null) {
            return $this->validationFailed(
                'platform',
                'Client-observed target platform is required for workload bootstrap.',
            );
        }

        if ($requireObservedPlatform && $architecture === null) {
            return $this->validationFailed(
                'architecture',
                'Client-observed target architecture is required for workload bootstrap.',
            );
        }

        $platform ??= 'ubuntu_24-04';
        $architecture ??= 'amd64';

        if (! in_array($platform, ['ubuntu_24-04', 'ubuntu_26-04'], true)) {
            return $this->validationFailed(
                'platform',
                'Workload bootstrap supports Ubuntu 24.04 and Ubuntu 26.04 targets.',
            );
        }

        if (! in_array($architecture, ['amd64', 'arm64'], true)) {
            return $this->validationFailed(
                'architecture',
                'Workload bootstrap supports amd64 and arm64 targets.',
            );
        }

        $tld = $input->stringOption('tld');

        if ($tld === null) {
            return $this->validationFailed('tld', 'Every node requires a unique TLD.');
        }

        if (! NodeTld::isValid($tld)) {
            return $this->validationFailed(
                'tld',
                'TLD must be a non-reserved lowercase DNS label without a leading dot.',
            );
        }

        $s3DataPath = $this->resolveS3DataPath($roles, $input);

        if ($s3DataPath instanceof GatewayActionResult) {
            return $s3DataPath;
        }

        $analyticsDatabaseNodes = $this->resolveAnalyticsDatabaseNodes($roles, $input);

        if ($analyticsDatabaseNodes instanceof GatewayActionResult) {
            return $analyticsDatabaseNodes;
        }

        return new WorkloadNodeProvisioningInput(
            host: $host ?? '',
            tld: $tld,
            sshUser: $needsHost ? $input->stringOption('user') ?? 'root' : null,
            gatewayEndpoint: $needsHost ? $gatewayEndpoint : null,
            hostKeyFingerprint: $needsHost ? $input->stringOption('host-key-fingerprint') : null,
            platform: $platform,
            architecture: $architecture,
            postgresNodeId: $analyticsDatabaseNodes['postgres_node_id'],
            postgresProcessId: $analyticsDatabaseNodes['postgres_process_id'],
            clickhouseNodeId: $analyticsDatabaseNodes['clickhouse_node_id'],
            s3DataPath: $s3DataPath,
        );
    }

    /** @param list<string> $roles */
    private function resolveS3DataPath(
        array $roles,
        NodeCreationInput $input,
    ): string|GatewayActionResult|null {
        $hasS3 = in_array(NodeRoleName::S3->value, $roles, true);
        $dataPath = $input->stringOption('s3-data-path');

        if (! $hasS3) {
            return $dataPath === null
                ? null
                : $this->validationFailed('s3_data_path', 'Only s3 nodes use --s3-data-path.');
        }

        $dataPath ??= S3RoleSettings::DefaultDataPath;

        try {
            return S3RoleSettings::fromArray(['data_path' => $dataPath])->dataPath;
        } catch (InvalidArgumentException $exception) {
            return $this->validationFailed('s3_data_path', $exception->getMessage());
        }
    }

    /**
     * @param  list<string>  $roles
     * @return array{postgres_node_id: ?int, postgres_process_id: ?int, clickhouse_node_id: ?int}|GatewayActionResult
     */
    private function resolveAnalyticsDatabaseNodes(
        array $roles,
        NodeCreationInput $input,
    ): array|GatewayActionResult {
        $hasAnalytics = in_array(NodeRoleName::Analytics->value, $roles, true);
        $postgresNodeName = $input->stringOption('postgres-node');
        $postgresProcessName = $input->stringOption('postgres-process');
        $clickhouseNodeName = $input->stringOption('clickhouse-node');

        if (! $hasAnalytics) {
            if ($postgresNodeName !== null) {
                return $this->validationFailed('postgres_node', 'Only analytics nodes use --postgres-node.');
            }

            if ($clickhouseNodeName !== null) {
                return $this->validationFailed('clickhouse_node', 'Only analytics nodes use --clickhouse-node.');
            }

            if ($postgresProcessName !== null) {
                return $this->validationFailed('postgres_process', 'Only analytics nodes use --postgres-process.');
            }

            return [
                'postgres_node_id' => null,
                'postgres_process_id' => null,
                'clickhouse_node_id' => null,
            ];
        }

        if ($postgresNodeName === null) {
            return $this->validationFailed('postgres_node', 'Analytics nodes require --postgres-node.');
        }

        if ($clickhouseNodeName === null) {
            return $this->validationFailed('clickhouse_node', 'Analytics nodes require --clickhouse-node.');
        }

        if ($postgresProcessName === null) {
            return $this->validationFailed('postgres_process', 'Analytics nodes require --postgres-process.');
        }

        $postgresNode = $this->findActiveDatabaseNodeByName($postgresNodeName);

        if (! $postgresNode instanceof Node) {
            return $this->validationFailed(
                'postgres_node',
                'Analytics nodes require an active database node for PostgreSQL.',
            );
        }

        $postgresProcess = Process::query()
            ->where('owner_type', $postgresNode->getMorphClass())
            ->where('owner_id', $postgresNode->getKey())
            ->where('name', $postgresProcessName)
            ->where('runtime_config->service', 'postgres')
            ->first();

        if (
            ! $postgresProcess instanceof Process
            || ! $this->analyticsDatabaseResolver->isPlausiblePostgresProcess($postgresProcess)
        ) {
            return $this->validationFailed(
                'postgres_process',
                'Analytics nodes require a PostgreSQL 16 process for Plausible on the assigned database node.',
            );
        }

        $clickhouseNode = $this->findActiveDatabaseNodeByName($clickhouseNodeName);

        if (! $clickhouseNode instanceof Node) {
            return $this->validationFailed(
                'clickhouse_node',
                'Analytics nodes require an active database node for ClickHouse.',
            );
        }

        return [
            'postgres_node_id' => $postgresNode->id,
            'postgres_process_id' => $postgresProcess->id,
            'clickhouse_node_id' => $clickhouseNode->id,
        ];
    }

    /**
     * @param  list<string>  $roles
     */
    private function resolveIngressPlacement(
        array $roles,
        NodeCreationInput $input,
    ): NodeCreationIngressPlacement|GatewayActionResult {
        $roles = array_values(array_unique($roles));
        $ingressNodeName = $input->stringOption('ingress');

        if (
            $ingressNodeName !== null
            && (! in_array(NodeRoleName::AppProduction->value, $roles, true)
            || in_array(NodeRoleName::Ingress->value, $roles, true))
        ) {
            return GatewayActionResult::error(
                code: 'validation_failed',
                message: '--ingress is only supported for private app-prod placement.',
                meta: ['field' => 'ingress_node'],
            );
        }

        if (! in_array(NodeRoleName::AppProduction->value, $roles, true)) {
            return new NodeCreationIngressPlacement($roles, null, null);
        }

        if (in_array(NodeRoleName::Ingress->value, $roles, true)) {
            return new NodeCreationIngressPlacement($this->orderWorkloadRoles($roles), null, null);
        }

        if ($ingressNodeName !== null) {
            $ingressNode = $this->findActiveIngressNodeByName($ingressNodeName);

            if (! $ingressNode instanceof Node) {
                return $this->missingIngressPlacement();
            }

            return new NodeCreationIngressPlacement(
                roles: $this->orderWorkloadRoles($roles),
                ingressNodeId: $ingressNode->id,
                ingressNodeName: $ingressNode->name,
            );
        }

        return $this->missingIngressPlacement('App-production requires explicit ingress placement.');
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function orderWorkloadRoles(array $roles): array
    {
        usort(
            $roles,
            static fn (string $first, string $second): int => (
                match ($first) {
                    NodeRoleName::Ingress->value => 10,
                    NodeRoleName::AppProduction->value => 20,
                    default => 30,
                } <=> match ($second) {
                    NodeRoleName::Ingress->value => 10,
                    NodeRoleName::AppProduction->value => 20,
                    default => 30,
                }
            ),
        );

        return $roles;
    }

    private function findActiveIngressNodeByName(string $name): ?Node
    {
        return Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn (Builder $query) => $query
                ->where('role', NodeRoleName::Ingress->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
    }

    private function findActiveDatabaseNodeByName(string $name): ?Node
    {
        return Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn (Builder $query) => $query
                ->where('role', NodeRoleName::Database->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
    }

    private function missingIngressPlacement(
        string $message = 'Private app-prod nodes require an active ingress node. Create one first with: orbit node:new edge-1 --template=ingress',
    ): GatewayActionResult {
        return GatewayActionResult::error(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => 'ingress_node',
                'required_role' => NodeRoleName::Ingress->value,
            ],
        );
    }

    private function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($host, '.') || strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        return array_all(
            explode('.', trim($host, '.')),
            fn ($label) => ! (
                $label === ''
                || strlen((string) $label) > 63
                || ! preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/', (string) $label)
            ),
        );
    }

    private function validationFailed(string $field, string $message): GatewayActionResult
    {
        return GatewayActionResult::error('validation_failed', $message, ['field' => $field]);
    }
}
