<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeAccessOperationCompletion;
use App\Domain\Nodes\NodeAccessOperationExecution;
use App\Domain\Nodes\NodeBootstrapOperationCompletion;
use App\Domain\Nodes\NodeChildAdmission;
use App\Domain\Nodes\NodeFootprintOperationCompletion;
use App\Domain\Nodes\NodeFootprintOperationExecution;
use App\Domain\Nodes\NodePhpPackagesOperationCompletion;
use App\Domain\Nodes\NodeRoleOperationCompletion;
use App\Domain\Nodes\NodeRoleOperationExecution;
use App\Domain\Nodes\NodeRoleRelocationSourceCompletion;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\NodeWireGuardOperationExecution;
use App\Domain\Operations\OperationKinds;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\BootstrapSshOperationTransport;

$bound = static fn (string $name, string $path, array $parameters): array => [
    'transport' => 'agent', 'name' => $name,
    'bind' => array_combine($parameters, array_map(static fn (string $parameter): string => 'input._footprint.payloads.'.$path.'.'.$parameter, $parameters)),
];

$wireguard = [
    'executor' => 'swamp',
    'completion' => NodeWireGuardOperationCompletion::class,
    'execution' => NodeWireGuardOperationExecution::class,
    'runs' => [
        ['transport' => 'gateway', 'name' => 'node.prepare-wireguard', 'input_fields' => ['context_node_id', 'excluded_node_id'], 'resumable' => true],
        ['transport' => 'node-wireguard', 'name' => 'wireguard.project-hub',
            'bind' => ['configuration' => 'input._wireguard.configuration', 'excluded_node_id' => 'input._wireguard.excluded_node_id'],
            'secrets' => ['configuration' => 'node.vpn_configuration']],
    ],
    'completion_contract' => ['step' => 'hub-project', 'transport' => 'node-wireguard', 'name' => 'wireguard.project-hub', 'result' => ['projected' => true]],
    'result_fields' => ['prepared', 'projected', 'error_code'],
];

$nodeKinds = [
    'node.wireguard_key' => $wireguard,
    'node.wireguard_hub_converge' => $wireguard,
    'node.access_remove' => [
        'executor' => 'swamp',
        'completion' => NodeAccessOperationCompletion::class,
        'execution' => NodeAccessOperationExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'node.remove-access-edge', 'input_values' => [], 'resumable' => true],
            ['transport' => 'agent', 'name' => 'caddy.reload', 'input_values' => []],
        ],
        'completion_contract' => ['step' => 'access-stream-revoke', 'transport' => 'agent', 'name' => 'caddy.reload', 'result' => []],
        'result_fields' => ['access_removed', 'error_code'],
    ],
    'node.prepare_php_packages' => [
        'admission' => NodeChildAdmission::class,
        'executor' => 'swamp',
        'completion' => NodePhpPackagesOperationCompletion::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'node.prepare-php-packages', 'input_fields' => ['versions', 'profile'], 'resumable' => true],
            ['transport' => 'agent', 'name' => 'php.install-build', 'bind' => array_combine(
                ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release'],
                array_map(static fn (string $field): string => 'input._php_packages.'.$field, ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release']),
            )],
        ],
        'completion_contract' => ['step' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'result' => []],
        'result_fields' => ['prepared', 'outcome', 'error_code'],
    ],
    'node.converge' => [
        'admission' => NodeChildAdmission::class,
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
            $bound('node.install-private-dns-listener', 'private-dns-listener', ['release_id', 'files', 'service', 'socket']),
        ],
        'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input._footprint.node_id'], [
            'route.caddy_converge', 'route.private_dns_publish', 'route.offline_residue_clean', 'ai_router.collector_converge',
        ]),
        'completion_contract' => ['step' => 'footprint-result', 'transport' => 'gateway', 'name' => 'node.footprint-result',
            'result' => ['footprint_proven' => true],
            'children_settled' => ['route.caddy_converge', 'route.private_dns_publish', 'route.offline_residue_clean', 'ai_router.collector_converge']],
        'result_fields' => ['prepared', 'installed', 'changed', 'restarted', 'version', 'footprint_proven', 'digest', 'error_code',
            ['name' => 'secret_hash', 'secret' => true, 'store' => 'node.agent_secret_hash'],
            ['name' => 'artifacts', 'list_of' => ['name' => 'string', 'status' => 'enum(applied,unchanged,skipped)', 'reason' => 'string|null']],
            ['name' => 'skipped', 'list_of' => ['child_id' => 'int', 'key' => 'string', 'kind' => 'string', 'reason' => 'string']],
        ],
    ],
    'node.bootstrap_install' => [
        'executor' => 'swamp',
        'completion' => NodeBootstrapOperationCompletion::class,
        'runs' => [[
            'transport' => 'bootstrap-ssh', 'name' => 'agent.install',
            'input_fields' => ['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_fingerprint', 'bootstrap_user', 'bootstrap_identity', 'command_input'],
            'input_values' => [],
            'secrets' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity'],
            'input_schema' => BootstrapSshOperationTransport::inputSchema('agent.install'),
            'bind' => [
                'agent_pin' => 'input.agent_pin', 'bootstrap_host' => 'input.bootstrap_host', 'bootstrap_port' => 'input.bootstrap_port',
                'bootstrap_fingerprint' => 'input.bootstrap_fingerprint', 'bootstrap_user' => 'input.bootstrap_user',
                'command_input' => 'input.command_input', 'bootstrap_identity' => 'input.bootstrap_identity',
            ],
        ]],
        'completion_contract' => [
            'step' => 'agent-install', 'transport' => 'bootstrap-ssh', 'name' => 'agent.install',
            'result' => ['installed' => true],
        ],
        'result_fields' => ['installed', 'changed', 'architecture', 'host_type', 'public_key', 'host_key_fingerprint', 'error_code'],
    ],
    'node.settings' => [
        'executor' => 'swamp',
        'completion' => NodeSettingsOperationCompletion::class,
        'runs' => [[
            'transport' => 'agent',
            'name' => 'node.prepare-storage-root',
            'input_fields' => ['root', 'user', 'group', 'create_missing'],
            'input_values' => [],
        ]],
        'completion_contract' => [
            'step' => 'node-storage-root',
            'transport' => 'agent',
            'name' => 'node.prepare-storage-root',
            'result' => [],
        ],
        'result_fields' => ['error_code'],
    ],
];

$nodeRoleDefinition = [
    'executor' => 'swamp', 'execution' => NodeRoleOperationExecution::class, 'completion' => NodeRoleOperationCompletion::class,
    'admission' => NodeChildAdmission::class,
    'runs' => [
        ...array_map(static fn (string $name): array => ['transport' => 'gateway', 'name' => $name, 'resumable' => true], [
            'node.prepare-role', 'node.role-result', 'node.grant-gateway-access', 'node.transfer-role',
        ]),
        ...array_map(static fn (string $name): array => ['transport' => 'agent', 'name' => $name], [
            'node.prepare-storage-root', 'caddy.install-package', 'caddy.order-after-vpn', 'firewall.rules', 'firewall.router-lan',
            'wireguard.gateway-dns', 'role.install-prerequisites', 'recipe.install-javascript', 'php.install-build',
            'ci-runner.host', 'hibernation.prepare-directories',
        ]),
    ],
    'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'], [
        'node.network_converge', 'node.role_relocate_source', 'node.role_relocate_target', 'node.converge', 'fleet.cli_converge', 'route.caddy_converge', 'route.private_dns_publish',
        'tool.manager_converge', 'metrics.reconcile', 'metrics.role_converge', 'metrics.role_remove',
        'analytics.role_converge', 'analytics.role_remove', 'websocket.role_converge', 'websocket.role_remove',
    ]),
    'completion_contract' => ['step' => 'role-result', 'transport' => 'gateway', 'name' => 'node.role-result', 'result' => ['role_proven' => true]],
    'result_fields' => ['prepared', 'role_proven', 'assignment_id', 'created', 'follow_up', 'failed_step', 'error_code', 'outcome', 'matches',
        'changed', 'configured', 'access_granted', 'version', 'transferred', 'role_wait_until'],
];

foreach (['node.role_add', 'node.role_converge', 'node.role_remove', 'node.role_relocate', 'node.role_relocate_source', 'node.role_relocate_target'] as $nodeRoleKind) {
    $nodeKinds[$nodeRoleKind] = $nodeRoleDefinition;
}

$nodeKinds['node.role_relocate_source']['completion'] = NodeRoleRelocationSourceCompletion::class;

if (! OperationKinds::supports('children') || ! OperationKinds::supports('children_settled') || ! OperationKinds::supports('list_of')) {
    unset($nodeKinds['node.converge'], $nodeKinds['node.role_add'], $nodeKinds['node.role_converge'], $nodeKinds['node.role_remove'], $nodeKinds['node.role_relocate'], $nodeKinds['node.role_relocate_source'], $nodeKinds['node.role_relocate_target']);
}

return $nodeKinds;
