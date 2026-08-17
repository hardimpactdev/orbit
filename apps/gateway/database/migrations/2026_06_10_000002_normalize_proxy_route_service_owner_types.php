<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align proxy route owner_type values with the b8c7532f docs contract.
 *
 * - websocket.orbit service route: owner_type 'websocket' → 'router'
 * - s3.orbit service route: owner_type 'tool' → 'router'
 * - public S3 host routes (protocol s3, not the service domain): owner_type 'tool' → 's3'
 */
return new class extends Migration {
    public function up(): void
    {
        $routerNodeId = $this->canonicalNodeId('router');
        $ingressNodeId = $this->canonicalNodeId('ingress');

        // Websocket service route: owner 'websocket' → 'router'
        $websocketRoutes = DB::table('proxy_routes')
            ->where('node_id', $routerNodeId ?? 0)
            ->where('domain', 'websocket.orbit')
            ->where('owner_type', 'websocket')
            ->where('kind', 'proxy')
            ->whereNull('app_id')
            ->whereNull('workspace_id')
            ->whereJsonContains('config->protocol', 'websocket');
        $this->whereInstanceIsNull($websocketRoutes)
            ->update(['owner_type' => 'router']);

        // S3 service route: owner 'tool' → 'router'
        $serviceRoutes = DB::table('proxy_routes')
            ->where('node_id', $routerNodeId ?? 0)
            ->where('domain', 's3.orbit')
            ->where('owner_type', 'tool')
            ->where('kind', 'proxy')
            ->whereNull('app_id')
            ->whereNull('workspace_id')
            ->whereJsonContains('config->protocol', 's3')
            ->whereJsonContains('config->owner_name', 'rustfs');
        $this->whereInstanceIsNull($serviceRoutes)
            ->update(['owner_type' => 'router']);

        // Public S3 host routes (all remaining rows with owner 'tool' and protocol s3
        // in config are public host routes — the service route was handled above).
        $publicRoutes = DB::table('proxy_routes')
            ->where('node_id', $ingressNodeId ?? 0)
            ->where('domain', '!=', 's3.orbit')
            ->where('owner_type', 'tool')
            ->where('kind', 'proxy')
            ->whereNull('app_id')
            ->whereNull('workspace_id')
            ->whereJsonContains('config->protocol', 's3')
            ->whereJsonContains('config->owner_name', 'rustfs');
        $this->whereInstanceIsNull($publicRoutes)
            ->update(['owner_type' => 's3']);
    }

    public function down(): void
    {
        $routerNodeId = $this->canonicalNodeId('router');
        $ingressNodeId = $this->canonicalNodeId('ingress');

        // Reverse: router-owned websocket.orbit → 'websocket'
        $websocketRoutes = DB::table('proxy_routes')
            ->where('node_id', $routerNodeId ?? 0)
            ->where('domain', 'websocket.orbit')
            ->where('owner_type', 'router')
            ->where('kind', 'proxy')
            ->whereNull('app_id')
            ->whereNull('workspace_id')
            ->whereJsonContains('config->protocol', 'websocket');
        $this->whereInstanceIsNull($websocketRoutes)
            ->update(['owner_type' => 'websocket']);

        // Reverse: router-owned s3.orbit → 'tool'
        $serviceRoutes = DB::table('proxy_routes')
            ->where('node_id', $routerNodeId ?? 0)
            ->where('domain', 's3.orbit')
            ->where('owner_type', 'router')
            ->where('kind', 'proxy')
            ->whereNull('app_id')
            ->whereNull('workspace_id')
            ->whereJsonContains('config->protocol', 's3')
            ->whereJsonContains('config->owner_name', 'rustfs');
        $this->whereInstanceIsNull($serviceRoutes)
            ->update(['owner_type' => 'tool']);

        // Reverse: public S3 routes → 'tool'
        $publicRoutes = DB::table('proxy_routes')
            ->where('node_id', $ingressNodeId ?? 0)
            ->where('domain', '!=', 's3.orbit')
            ->where('owner_type', 's3')
            ->where('kind', 'proxy')
            ->whereNull('app_id')
            ->whereNull('workspace_id')
            ->whereJsonContains('config->protocol', 's3')
            ->whereJsonContains('config->owner_name', 'rustfs');
        $this->whereInstanceIsNull($publicRoutes)
            ->update(['owner_type' => 'tool']);
    }

    private function whereInstanceIsNull(Builder $query): Builder
    {
        if (Schema::hasColumn('proxy_routes', 'instance_id')) {
            $query->whereNull('instance_id');
        }

        return $query;
    }

    private function canonicalNodeId(string $role): ?int
    {
        $nodeId = DB::table('nodes')
            ->join('node_role', 'node_role.node_id', '=', 'nodes.id')
            ->where('nodes.status', 'active')
            ->where('node_role.role', $role)
            ->where('node_role.status', 'active')
            ->orderBy('nodes.id')
            ->value('nodes.id');

        return is_int($nodeId) ? $nodeId : null;
    }
};
