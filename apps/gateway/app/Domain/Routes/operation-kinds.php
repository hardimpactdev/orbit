<?php

declare(strict_types=1);

use App\Actions\Routes\RouteChildAdmission;
use App\Domain\Operations\OperationKinds;
use App\Domain\Routes\AnalyticsRouteParentExecution;
use App\Domain\Routes\CaddyOperationCompletion;
use App\Domain\Routes\ClusterRouterParentExecution;
use App\Domain\Routes\InstanceRuntimeExecution;
use App\Domain\Routes\OfflineResidueCompletion;
use App\Domain\Routes\OfflineResidueExecution;
use App\Domain\Routes\RouteKitAvailability;
use App\Domain\Routes\RouteNodeEffectExecution;
use App\Domain\Routes\RouteParentExecution;
use App\Domain\Routes\RouteWorkloadExecution;

$effect = static fn (string $command, array $secrets = []): array => [
    'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => RouteNodeEffectExecution::class,
    'runs' => [
        ['transport' => 'gateway', 'name' => 'route.node-effect', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
        ['transport' => 'agent', 'name' => $command, 'input_fields' => [], 'resumable' => true, 'input_values' => [], 'secrets' => $secrets],
    ],
    'completion_contract' => ['step' => 'node-effect-proof', 'transport' => 'gateway', 'name' => 'route.node-effect', 'result' => ['ready' => true]],
    'result_fields' => ['prepared', 'ready', 'error_code'],
];

$definitions = [
    'route.router_lan_retire' => $effect('firewall.rules'),
    'route.workload_firewall' => $effect('firewall.rules'),
    'route.lan_converge' => $effect('firewall.route-lan'),
    'route.lan_remove' => $effect('firewall.route-lan'),
    'route.public_firewall' => $effect('firewall.rules'),
    'route.shared_php_withdraw' => $effect('php.converge-shared-pools', ['configurations' => 'route.php_pools']),
    'route.transfer_php_withdraw' => $effect('php.converge-shared-pools', ['configurations' => 'route.php_pools']),
    'route.transfer_lan_remove' => $effect('firewall.route-lan'),

    'route.workload_prepare' => [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => RouteWorkloadExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.workload', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-workload', 'name' => 'instance.grant-development-caddy-access', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-workload', 'name' => 'php.inspect-shared-pools', 'input_fields' => [], 'resumable' => true, 'input_values' => [], 'secrets' => ['configurations' => 'route.php_pools']],
            ['transport' => 'route-workload', 'name' => 'php.install-build', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-workload', 'name' => 'php.configure-runtime', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-workload', 'name' => 'php.converge-shared-pools', 'input_fields' => [], 'resumable' => true, 'input_values' => [], 'secrets' => ['configurations' => 'route.php_pools']],
        ],
        'completion_contract' => ['step' => 'workload-proof', 'transport' => 'gateway', 'name' => 'route.workload', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'inspected', 'ready', 'error_code'],
    ],

    'route.offline_residue_clean' => [
        'executor' => 'swamp', 'completion' => OfflineResidueCompletion::class, 'execution' => OfflineResidueExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.offline-residue', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-caddy', 'name' => 'caddy.publish', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-dns', 'name' => 'route.publish-dns', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'agent', 'name' => 'php.converge-shared-pools', 'input_fields' => [], 'resumable' => true, 'input_values' => [], 'secrets' => ['configurations' => 'route.php_pools']],
            ['transport' => 'agent', 'name' => 'certificate.remove-leaf', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'agent', 'name' => 'firewall.route-lan', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
        ],
        'completion_contract' => ['step' => 'offline-residue-proof', 'transport' => 'gateway', 'name' => 'route.offline-residue', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'ready', 'result', 'outcome', 'data.ready', 'data.digest', 'error_code', ['name' => 'removed', 'type' => 'list', 'items' => 'string', 'max' => 4096]],
    ],
    ...array_fill_keys(['route.withdraw_instance', 'route.cleanup_instance'], [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => InstanceRuntimeExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.instance-runtime', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'agent', 'name' => 'php.converge-shared-pools', 'input_fields' => [], 'resumable' => true, 'input_values' => [], 'secrets' => ['configurations' => 'route.php_pools']],
            ['transport' => 'agent', 'name' => 'php.remove-production-runtime', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-caddy', 'name' => 'caddy.publish', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
            ['transport' => 'agent', 'name' => 'certificate.remove-leaf', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
        ],
        'completion_contract' => ['step' => 'instance-runtime-proof', 'transport' => 'gateway', 'name' => 'route.instance-runtime', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'ready', 'withdrawn', 'result', 'error_code'],
    ]),

    ...array_fill_keys(['route.workload_verify', 'route.public_verify'], [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'runs' => [],
        'completion_contract' => ['step' => 'route-verified', 'transport' => 'swamp', 'name' => 'operation-route-verification', 'result' => ['ready' => true]],
        'result_fields' => ['ready', 'error_code'],
    ]),
    'route.caddy_converge' => [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'runs' => [],
        'completion_contract' => ['step' => 'caddy-publish', 'transport' => 'swamp', 'name' => 'operation-route-publication', 'result' => ['ready' => true]],
        'result_fields' => ['version', 'result', 'ready', 'stage', 'error_code'],
    ],
    'route.private_dns_publish' => [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'runs' => [],
        'completion_contract' => ['step' => 'private-dns-publish', 'transport' => 'swamp', 'name' => 'operation-route-publication', 'result' => ['ready' => true]],
        'result_fields' => ['outcome', 'data.ready', 'data.digest', 'ready', 'error_code'],
    ],
];

if (! OperationKinds::supports('list_of')) {
    unset($definitions['route.offline_residue_clean']);
}

if (OperationKinds::supports('children') && OperationKinds::supports('list_of')) {
    foreach (AnalyticsRouteParentExecution::Kinds as $kind) {
        $childKinds = ['certificate.converge', 'certificate.remove', 'route.caddy_converge', 'route.private_dns_publish', 'route.public_verify', 'route.public_firewall'];
        $definitions[$kind] = [
            'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => AnalyticsRouteParentExecution::class,
            'runs' => [['transport' => 'gateway', 'name' => 'route.analytics-parent', 'resumable' => true, 'input_fields' => [], 'input_values' => []]],
            'children' => array_map(static fn (string $child): array => ['kind' => $child, 'node_from' => 'intent.node_ids'], RouteKitAvailability::registeredChildren($childKinds)),
            'completion_contract' => ['step' => 'route-proof', 'transport' => 'gateway', 'name' => 'route.analytics-parent', 'result' => ['ready' => true]],
            'result_fields' => ['prepared', 'mode', 'production', 'public', 'remote_router', 'web_root', 'targeted', 'opened', 'ready', 'stage', 'route_id', 'wait_seconds', 'error_code',
                ['name' => 'child_phases', 'max' => 4096, 'list_of' => ['stage' => 'string', 'key' => 'string']]],
        ];
    }
}

if (OperationKinds::supports('children') && OperationKinds::supports('list_of')) {
    $children = ['certificate.converge', 'certificate.remove', 'route.caddy_converge', 'route.private_dns_publish',
        'route.workload_prepare', 'route.workload_firewall', 'route.lan_converge', 'route.lan_remove', 'route.router_lan_retire',
        'route.workload_verify', 'route.public_verify', 'route.public_firewall', 'route.shared_php_withdraw',
        'route.transfer_php_withdraw', 'route.transfer_lan_remove', 'instance.prepare_php_runtime', 'instance.prepare_served_web_roots',
        'instance.sync_environment', 'instance.destroy', 'node.role_converge', 'node.role_remove', 'metrics.reconcile'];
    foreach ([...RouteParentExecution::Kinds, ...ClusterRouterParentExecution::Kinds] as $kind) {
        $router = str_starts_with($kind, 'cluster.');
        $definitions[$kind] = [
            'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class,
            'execution' => $router ? ClusterRouterParentExecution::class : RouteParentExecution::class,
            'runs' => [['transport' => 'gateway', 'name' => $router ? 'route.cluster-router' : 'route.parent',
                'resumable' => true, 'input_fields' => [], 'input_values' => []]],
            'children' => array_map(static fn (string $child): array => ['kind' => $child, 'node_from' => 'intent.node_ids'], RouteKitAvailability::registeredChildren($children)),
            // The final command requires each exact required keyed child, including optional phases.
            'completion_contract' => ['step' => 'route-proof', 'transport' => 'gateway',
                'name' => $router ? 'route.cluster-router' : 'route.parent', 'result' => ['ready' => true]],
            'result_fields' => ['prepared', 'mode', 'production', 'public', 'remote_router', 'web_root', 'targeted', 'opened', 'ready', 'stage',
                'route_id', 'cluster_id', 'wait_seconds', 'activate_public', 'withdraw_public', 'changed', 'metrics', 'route_branch', 'failure_code', 'error_code',
                ['name' => 'child_phases', 'max' => 4096, 'list_of' => ['stage' => 'string', 'key' => 'string']],
                ['name' => 'retained_on_nodes', 'max' => 128, 'list_of' => ['node_id' => 'int', 'node' => 'string',
                    'steps' => ['type' => 'list', 'items' => 'string', 'max' => 32]]]],
        ];
    }
}

if (OperationKinds::supports('children')) {
    foreach ($definitions as $kind => &$definition) {
        if (str_starts_with($kind, 'route.')) {
            $definition['admission'] = RouteChildAdmission::class;
        }
    }
    unset($definition);
}

return $definitions;
