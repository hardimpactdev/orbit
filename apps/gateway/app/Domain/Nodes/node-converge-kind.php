<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeChildAdmission;
use App\Domain\Nodes\NodeFootprintOperationCompletion;
use App\Domain\Nodes\NodeFootprintOperationExecution;
use App\Infrastructure\Nodes\AgentCaddyPackage;

$bound = static fn (string $name, string $path, array $parameters): array => [
    'transport' => 'agent', 'name' => $name,
    'bind' => array_combine($parameters, array_map(static fn (string $parameter): string => 'input._footprint.payloads.'.$path.'.'.$parameter, $parameters)),
];

return [
    'admission' => NodeConvergeChildAdmission::class,
    'execution' => NodeFootprintOperationExecution::class,
    'executor' => 'swamp',
    'completion' => NodeFootprintOperationCompletion::class,
    'runs' => [
        ['transport' => 'gateway', 'name' => 'node.prepare-footprint', 'resumable' => true, 'input_fields' => ['force_artifacts', 'first_visit']],
        ['transport' => 'gateway', 'name' => 'node.prepare-agent-configuration', 'resumable' => true, 'input_values' => []],
        ['transport' => 'gateway', 'name' => 'node.footprint-result', 'resumable' => true, 'input_values' => []],
        ['transport' => 'node-agent', 'name' => 'agent.install', 'input_fields' => ['pin'], 'bind' => ['pin' => 'input._footprint.agent_pin']],
        [...$bound('agent.configure', 'agent', ['secret_hash', 'configuration', 'certificate', 'unit', 'checkouts']),
            'secrets' => ['secret_hash' => 'node.agent_secret_hash']],
        $bound('caddy.install-package', 'caddy-package', array_keys(AgentCaddyPackage::input())),
        $bound('wireguard.repair-peer-dns', 'peer-dns', ['dns_server', 'public_key', 'domains']),
        $bound('node.publish-tmpfiles', 'tmpfiles', ['rule']),
        // Exact version inputs and order are bound by the family execution descriptors.
        ['transport' => 'agent', 'name' => 'php.configure-runtime'],
        $bound('node.install-private-dns-listener', 'private-dns-listener', ['release_id', 'files', 'service', 'socket']),
    ],
    'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input._footprint.node_id'], [
        'route.caddy_converge', 'route.private_dns_publish', 'route.offline_residue_clean', 'ai_router.collector_converge',
    ]),
    'completion_contract' => ['step' => 'footprint-result', 'transport' => 'gateway', 'name' => 'node.footprint-result',
        'result' => ['footprint_proven' => true],
        'children_settled' => ['route.caddy_converge', 'route.private_dns_publish', 'route.offline_residue_clean', 'ai_router.collector_converge']],
    'result_fields' => ['prepared', 'installed', 'changed', 'restarted', 'reloaded', 'version', 'footprint_proven', 'digest', 'error_code',
        ['name' => 'secret_hash', 'secret' => true, 'store' => 'node.agent_secret_hash'],
        ['name' => 'deferred', 'type' => 'list', 'items' => 'string', 'max' => 4096],
        ['name' => 'artifacts', 'list_of' => ['name' => 'string', 'status' => 'enum(applied,unchanged,skipped)', 'reason' => 'string|null']],
        ['name' => 'skipped', 'list_of' => ['child_id' => 'int', 'key' => 'string', 'kind' => 'string', 'reason' => 'string']],
    ],
];
