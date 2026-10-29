<?php

declare(strict_types=1);

use App\Actions\Routes\RouteChildAdmission;
use App\Domain\Routes\CaddyOperationCompletion;
use App\Domain\Routes\RouteNodeExecution;

$definitions = [
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
        'result_fields' => ['prepared', 'version', 'result', 'ready', 'stage', 'error_code'],
    ],
];
foreach ($definitions as &$definition) {
    $definition['admission'] = RouteChildAdmission::class;
}
unset($definition);

return $definitions;
