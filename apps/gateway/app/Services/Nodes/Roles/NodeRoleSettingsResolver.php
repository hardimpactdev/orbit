<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\Analytics\AnalyticsDatabaseResolver;
use Illuminate\Http\JsonResponse;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class NodeRoleSettingsResolver
{
    public function __construct(
        private AnalyticsDatabaseResolver $analyticsDatabaseResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|JsonResponse
     */
    public function resolveAppProduction(
        Node $node,
        ?string $ingressNodeName,
        array $settings,
    ): array|JsonResponse {
        $ingressAssignment = $node
            ->roleAssignments()
            ->where('role', 'ingress')
            ->where('status', NodeRoleStatus::Active->value)
            ->first();

        if ($ingressAssignment instanceof NodeRoleAssignment) {
            if ($ingressNodeName !== null) {
                return $this->error(
                    'validation_failed',
                    'The app-prod role does not accept ingress_node when the target node already hosts ingress.',
                    ['field' => 'ingress_node', 'role' => 'app-prod'],
                    422,
                );
            }

            $settings['ingress_node_id'] = $node->id;

            return $settings;
        }

        if ($ingressNodeName === null) {
            return $this->error(
                'validation_failed',
                'The app-prod role requires an active ingress node.',
                ['field' => 'ingress_node', 'required_role' => 'ingress'],
                422,
            );
        }

        $ingressNode = Node::query()
            ->where('name', $ingressNodeName)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('role', 'ingress')
                ->where('status', NodeRoleStatus::Active->value))
            ->first();

        if (! $ingressNode instanceof Node) {
            return $this->error(
                'validation_failed',
                'The app-prod role requires an active ingress node.',
                ['field' => 'ingress_node', 'required_role' => 'ingress'],
                422,
            );
        }

        $settings['ingress_node_id'] = $ingressNode->id;

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|JsonResponse
     */
    public function resolveAnalytics(
        array $settings,
        ?string $postgresNodeName,
        ?string $postgresProcessName,
        ?string $clickhouseNodeName,
    ): array|JsonResponse {
        $postgresNodeName ??= is_string($settings['postgres_node'] ?? null) ? $settings['postgres_node'] : null;
        $clickhouseNodeName ??= is_string($settings['clickhouse_node'] ?? null) ? $settings['clickhouse_node'] : null;
        $postgresProcessName ??= is_string($settings['postgres_process'] ?? null)
            ? $settings['postgres_process']
            : null;

        unset($settings['postgres_node'], $settings['postgres_process'], $settings['clickhouse_node']);

        if (! array_key_exists('postgres_node_id', $settings)) {
            if ($postgresNodeName === null) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an active database node for PostgreSQL.',
                    ['field' => 'postgres_node', 'required_role' => 'database'],
                    422,
                );
            }

            $postgresNode = $this->findActiveDatabaseNodeByName($postgresNodeName);

            if (! $postgresNode instanceof Node) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an active database node for PostgreSQL.',
                    ['field' => 'postgres_node', 'required_role' => 'database'],
                    422,
                );
            }

            $settings['postgres_node_id'] = $postgresNode->id;
        }

        if (! array_key_exists('postgres_process_id', $settings)) {
            if ($postgresProcessName === null) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an explicit PostgreSQL process.',
                    ['field' => 'postgres_process'],
                    422,
                );
            }

            $postgresNodeId = $settings['postgres_node_id'] ?? null;
            $postgresNode = is_int($postgresNodeId) ? Node::query()->find($postgresNodeId) : null;
            $postgresProcess = $postgresNode instanceof Node
                ? Process::query()
                    ->where('owner_type', $postgresNode->getMorphClass())
                    ->where('owner_id', $postgresNode->getKey())
                    ->where('name', $postgresProcessName)
                    ->where('runtime_config->service', 'postgres')
                    ->first()
                : null;

            if (
                ! $postgresProcess instanceof Process
                || ! $this->analyticsDatabaseResolver->isPlausiblePostgresProcess($postgresProcess)
            ) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires a PostgreSQL 16 process for Plausible on its assigned database node.',
                    ['field' => 'postgres_process', 'value' => $postgresProcessName],
                    422,
                );
            }

            $settings['postgres_process_id'] = $postgresProcess->id;
        }

        if (! array_key_exists('clickhouse_node_id', $settings)) {
            if ($clickhouseNodeName === null) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an active database node for ClickHouse.',
                    ['field' => 'clickhouse_node', 'required_role' => 'database'],
                    422,
                );
            }

            $clickhouseNode = $this->findActiveDatabaseNodeByName($clickhouseNodeName);

            if (! $clickhouseNode instanceof Node) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an active database node for ClickHouse.',
                    ['field' => 'clickhouse_node', 'required_role' => 'database'],
                    422,
                );
            }

            $settings['clickhouse_node_id'] = $clickhouseNode->id;
        }

        return $settings;
    }

    private function findActiveDatabaseNodeByName(string $name): ?Node
    {
        return Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('role', 'database')
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }
}
