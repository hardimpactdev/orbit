<?php

declare(strict_types=1);

use App\Domain\Routes\CaddyOperationCompletion;
use App\Domain\Routes\InstanceRuntimeExecution;
use App\Domain\Routes\OfflineResidueCompletion;
use App\Domain\Routes\OfflineResidueExecution;
use App\Domain\Routes\RouteNodeEffectExecution;
use App\Domain\Routes\RouteNodeExecution;
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

return [
    'route.lan_converge' => $effect('firewall.route-lan'),
    'route.lan_remove' => $effect('firewall.route-lan'),
    'route.workload_verify' => $effect('route.verify-certificate'),
    'route.public_verify' => [...$effect('route.verify-certificate'), 'runs' => [
        ['transport' => 'gateway', 'name' => 'route.node-effect', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
        ['transport' => 'agent', 'name' => 'route.verify-certificate', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
        ['transport' => 'agent', 'name' => 'route.verify-http', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
    ]],
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
        'result_fields' => ['prepared', 'ready', 'result', 'outcome', 'data.ready', 'data.digest', 'error_code', ['name' => 'removed', 'type' => 'list', 'items' => 'string', 'max' => 10000]],
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

    'route.private_dns_publish' => [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => RouteNodeExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.private-dns-prepare', 'input_fields' => ['reason'], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-dns', 'name' => 'route.publish-dns', 'input_fields' => [], 'resumable' => true, 'input_values' => [],
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['records', 'catalog'],
                    'properties' => ['records' => ['type' => 'string'], 'catalog' => ['type' => 'string']]]],
        ],
        'completion_contract' => ['step' => 'private-dns-publish', 'transport' => 'route-dns', 'name' => 'route.publish-dns', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'version', 'outcome', 'data.ready', 'data.digest', 'ready', 'error_code'],
    ],
    'route.caddy_converge' => [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => RouteNodeExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.caddy-prepare', 'input_fields' => ['reason'], 'resumable' => true, 'input_values' => []],
            ['transport' => 'route-caddy', 'name' => 'caddy.publish', 'input_fields' => [], 'resumable' => true, 'input_values' => []],
        ],
        'completion_contract' => ['step' => 'caddy-publish', 'transport' => 'route-caddy', 'name' => 'caddy.publish', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'version', 'result', 'ready', 'error_code'],
    ],
];
