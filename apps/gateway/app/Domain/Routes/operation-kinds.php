<?php

declare(strict_types=1);

use App\Domain\Routes\RouteNodeExecution;
use App\Domain\Routes\CaddyOperationCompletion;

return [
    'route.offline_residue_clean' => [
        'executor' => 'swamp', 'completion' => \App\Domain\Routes\OfflineResidueCompletion::class, 'execution' => \App\Domain\Routes\OfflineResidueExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.offline-residue', 'input_fields' => [], 'input_values' => []],
            ['transport' => 'route-caddy', 'name' => 'caddy.publish', 'input_fields' => [], 'input_values' => []],
            ['transport' => 'route-dns', 'name' => 'route.publish-dns', 'input_fields' => [], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'php.converge-shared-pools', 'input_fields' => [], 'input_values' => [], 'secrets' => ['configurations' => 'route.php_pools']],
            ['transport' => 'agent', 'name' => 'certificate.remove-leaf', 'input_fields' => [], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'firewall.route-lan', 'input_fields' => [], 'input_values' => []],
        ],
        'completion_contract' => ['step' => 'offline-residue-proof', 'transport' => 'gateway', 'name' => 'route.offline-residue', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'ready', 'result', 'outcome', 'data.ready', 'data.digest', 'error_code', ['name' => 'removed', 'type' => 'list', 'items' => 'string', 'max' => 10000]],
    ],
    ...array_fill_keys(['route.withdraw_instance', 'route.cleanup_instance'], [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => \App\Domain\Routes\InstanceRuntimeExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.instance-runtime', 'input_fields' => [], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'php.converge-shared-pools', 'input_fields' => [], 'input_values' => [], 'secrets' => ['configurations' => 'route.php_pools']],
            ['transport' => 'agent', 'name' => 'php.remove-production-runtime', 'input_fields' => [], 'input_values' => []],
            ['transport' => 'route-caddy', 'name' => 'caddy.publish', 'input_fields' => [], 'input_values' => []],
            ['transport' => 'agent', 'name' => 'certificate.remove-leaf', 'input_fields' => [], 'input_values' => []],
        ],
        'completion_contract' => ['step' => 'instance-runtime-proof', 'transport' => 'gateway', 'name' => 'route.instance-runtime', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'ready', 'withdrawn', 'result', 'error_code'],
    ]),

    'route.private_dns_publish' => [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => RouteNodeExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.private-dns-prepare', 'input_fields' => ['reason'], 'input_values' => []],
            ['transport' => 'route-dns', 'name' => 'route.publish-dns', 'input_fields' => [], 'input_values' => [],
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['records', 'catalog'],
                    'properties' => ['records' => ['type' => 'string'], 'catalog' => ['type' => 'string']]]],
        ],
        'completion_contract' => ['step' => 'private-dns-publish', 'transport' => 'route-dns', 'name' => 'route.publish-dns', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'version', 'outcome', 'data.ready', 'data.digest', 'ready', 'error_code'],
    ],
    'route.caddy_converge' => [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => RouteNodeExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'route.caddy-prepare', 'input_fields' => ['reason'], 'input_values' => []],
            ['transport' => 'route-caddy', 'name' => 'caddy.publish', 'input_fields' => [], 'input_values' => []],
        ],
        'completion_contract' => ['step' => 'caddy-publish', 'transport' => 'route-caddy', 'name' => 'caddy.publish', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'version', 'result', 'ready', 'error_code'],
    ],
];
