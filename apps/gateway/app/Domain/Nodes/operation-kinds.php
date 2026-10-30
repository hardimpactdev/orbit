<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeChildAdmission;
use App\Domain\Nodes\NodeEnrollmentOperationCompletion;
use App\Domain\Nodes\NodeLifecycleChildAdmission;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Nodes\NodeNetworkOperationExecution;
use App\Domain\Nodes\NodePhpPackagesChildAdmission;
use App\Domain\Nodes\NodePhpPackagesOperationCompletion;
use App\Domain\Nodes\NodePhpPackagesOperationExecution;
use App\Domain\Nodes\NodeRemovalOperationCompletion;
use App\Domain\Nodes\NodeRoleOperationCompletion;
use App\Domain\Nodes\NodeRoleOperationExecution;
use App\Domain\Nodes\NodeRoleRelocationSourceCompletion;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\NodeWireGuardOperationExecution;

$nodeKinds = [
    'node.prepare_php_packages' => [
        'executor' => 'swamp',
        'admission' => NodePhpPackagesChildAdmission::class,
        'completion' => NodePhpPackagesOperationCompletion::class,
        'execution' => NodePhpPackagesOperationExecution::class,
        'runs' => [[
            'transport' => 'agent', 'name' => 'php.install-build',
            'bind' => array_combine(
                ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release'],
                array_map(static fn (string $field): string => 'input._php_packages.'.$field, ['versions', 'mirror', 'pcov', 'opentelemetry', 'extras', 'fpm', 'repack', 'release']),
            ),
        ]],
        'completion_contract' => ['step' => 'php-packages', 'transport' => 'agent', 'name' => 'php.install-build', 'result' => []],
        'result_fields' => ['outcome', 'error_code',
            ['name' => 'stale_masters', 'type' => 'list', 'items' => 'string', 'max' => 256],
            ['name' => 'deferred', 'type' => 'list', 'items' => 'string', 'max' => 256]],
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
$nodeKinds['node.converge'] = require __DIR__.'/node-converge-kind.php';

$wireguard = [
    'admission' => NodeChildAdmission::class,
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

$nodeKinds['node.wireguard_hub_converge'] = $wireguard;

$nodeLifecycleDefinition = [
    'executor' => 'swamp', 'admission' => NodeChildAdmission::class, 'execution' => NodeLifecycleOperationExecution::class,
    'completion' => NodeWireGuardOperationCompletion::class,
    'runs' => [
        ...array_map(static fn (string $name): array => ['transport' => 'gateway', 'name' => $name, 'input_values' => [], 'resumable' => true],
            ['node.lifecycle-result']),
        ...array_map(static fn (string $name): array => ['transport' => 'agent', 'name' => $name],
            ['firewall.router-lan', 'wireguard.repair-peer-dns']),
    ],
    'completion_contract' => ['step' => 'node-lifecycle-result', 'transport' => 'gateway', 'name' => 'node.lifecycle-result', 'result' => ['node_plan_proven' => true]],
    'result_fields' => ['node_plan_proven', 'projected', 'matches', 'changed', 'outcome', 'error_code', 'failed_step'],
];
$nodeKinds['node.network_projection'] = $nodeLifecycleDefinition;
foreach (['node.enrollment_begin' => ['node.begin-enrollment'], 'node.enrollment_activate' => ['node.activate-enrollment'],
    'node.enrollment_restore' => ['node.restore-enrollment-state'], 'node.enrollment_commit' => [], 'node.enrollment_settings' => []] as $enrollmentKind => $enrollmentCommands) {
    $nodeKinds[$enrollmentKind] = [...$nodeLifecycleDefinition,
        'runs' => [
            ...array_map(static fn (string $name): array => ['transport' => 'gateway', 'name' => $name, 'input_values' => [], 'resumable' => true],
                [...$enrollmentCommands, 'node.lifecycle-result']),
            ...match ($enrollmentKind) {
                'node.enrollment_commit' => [['transport' => 'agent', 'name' => 'wireguard.commit-peer']],
                'node.enrollment_settings' => [['transport' => 'agent', 'name' => 'node.prepare-storage-root']],
                default => [],
            },
        ],
        'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'],
            $enrollmentKind === 'node.enrollment_restore' ? ['node.network_rollback', 'node.network_projection', 'node.wireguard_hub_converge',
                'route.private_dns_publish', 'route.caddy_converge', 'metrics.reconcile'] : []),
        'result_fields' => ['node_plan_proven', 'preparing', 'activated', 'committed', 'restoring', 'restored', 'settings_applied', 'error_code', 'failed_step'],
    ];
}
$nodeKinds['node.enrollment_settings']['completion'] = NodeSettingsOperationCompletion::class;
$nodeKinds['node.add'] = [...$nodeLifecycleDefinition,
    'admission' => NodeLifecycleChildAdmission::class,
    'completion' => NodeEnrollmentOperationCompletion::class,
    'runs' => [['transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input_values' => [], 'resumable' => true]],
    'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'], [
        'node.enrollment_begin', 'node.enrollment_activate', 'node.enrollment_commit', 'node.enrollment_restore', 'node.enrollment_settings',
        'node.bootstrap_probe', 'node.bootstrap_install', 'node.bootstrap_host', 'node.bootstrap_verify', 'node.macos_inspect',
        'node.network_converge', 'node.network_projection', 'node.role_add', 'node.converge', 'fleet.cli_converge', 'tool.manager_converge', 'route.update', 'route.private_dns_publish', 'metrics.reconcile',
    ]),
    'compensations' => ['node.enrollment_restore'], 'cancellation_boundary' => 'add-begin',
    'result_fields' => ['node_plan_proven', 'node_restore_required', 'id', 'name', 'enrolled', 'follow_up', 'error_code', 'failed_step'],
];
foreach (['node.bootstrap_host' => ['node.bootstrap'], 'node.bootstrap_verify' => ['agent.status', 'firewall.rules'], 'node.macos_inspect' => ['node.inspect-macos-enrollment']] as $bootstrapKind => $bootstrapCommands) {
    $nodeKinds[$bootstrapKind] = [...$nodeLifecycleDefinition,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input_values' => [], 'resumable' => true],
            ...array_map(static fn (string $name): array => ['transport' => 'bootstrap-ssh', 'name' => $name,
                'resumable' => in_array($name, ['agent.status', 'node.inspect-macos-enrollment'], true),
                'bind' => array_combine(['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'bootstrap_identity'],
                    array_map(static fn (string $field): string => 'input.'.$field,
                        ['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'bootstrap_identity'])),
                'secrets' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity'],
            ], $bootstrapCommands),
        ],
        'result_fields' => ['node_plan_proven', 'bootstrapped', 'bootstrap_verified', 'matches', 'changed', 'architecture', 'platform', 'sha256', 'version', 'observer', 'error_code', 'failed_step'],
    ];
}
$nodeKinds['node.removal_begin'] = [...$nodeLifecycleDefinition,
    'runs' => [
        ['transport' => 'gateway', 'name' => 'node.begin-removal', 'input_values' => [], 'resumable' => true],
        ['transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input_values' => [], 'resumable' => true],
    ],
    'result_fields' => ['node_plan_proven', 'removing', 'error_code', 'failed_step'],
];
$nodeKinds['node.removal_restore'] = [...$nodeLifecycleDefinition,
    'runs' => [
        ['transport' => 'gateway', 'name' => 'node.restore-removal-state', 'input_values' => [], 'resumable' => true],
        ['transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input_values' => [], 'resumable' => true],
    ],
    'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'],
        ['node.network_projection', 'node.wireguard_hub_converge', 'route.private_dns_publish', 'route.caddy_converge', 'metrics.reconcile']),
    'result_fields' => ['node_plan_proven', 'restoring', 'restored', 'error_code', 'failed_step'],
];
$nodeKinds['node.remove'] = [...$nodeLifecycleDefinition,
    'admission' => NodeLifecycleChildAdmission::class,
    'completion' => NodeRemovalOperationCompletion::class,
    'runs' => [
        ['transport' => 'gateway', 'name' => 'node.lifecycle-result', 'input_values' => [], 'resumable' => true],
        ['transport' => 'agent', 'name' => 'agent.remove', 'input_values' => []],
        ['transport' => 'agent', 'name' => 'firewall.rules'],
    ],
    'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'],
        ['node.role_remove', 'node.removal_begin', 'node.removal_restore', 'node.network_projection', 'node.wireguard_hub_converge',
            'route.offline_residue_clean', 'route.private_dns_publish', 'route.caddy_converge', 'metrics.reconcile']),
    'compensations' => ['node.removal_restore'], 'cancellation_boundary' => 'remove-begin',
    'result_fields' => ['node_plan_proven', 'node_restore_required', 'id', 'name', 'removed', 'wireguard_peer_removed', 'dns_records_removed',
        'degradation', 'follow_up', 'matches', 'error_code', 'failed_step',
        ['name' => 'roles_shed', 'type' => 'list', 'items' => 'string', 'max' => 32],
        ['name' => 'retained_on_node', 'type' => 'list', 'items' => 'string', 'max' => 128]],
];

$nodeKinds['node.network_converge'] = [
    'executor' => 'swamp', 'admission' => NodeChildAdmission::class,
    'execution' => NodeNetworkOperationExecution::class,
    'completion' => NodeWireGuardOperationCompletion::class,
    'runs' => [
        ['transport' => 'gateway', 'name' => 'node.prepare-network', 'input_fields' => ['roleless_operator'], 'resumable' => true],
        ['transport' => 'gateway', 'name' => 'node.network-result', 'input_values' => [], 'resumable' => true],
        ...array_map(static fn (string $name): array => [
            'transport' => 'bootstrap-ssh', 'name' => $name,
            'bind' => array_combine(
                ['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_fingerprint', 'bootstrap_user', 'bootstrap_identity'],
                array_map(static fn (string $field): string => 'input.'.$field,
                    ['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_fingerprint', 'bootstrap_user', 'bootstrap_identity'])),
            'secrets' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity'],
        ], ['wireguard.prepare-peer-key', 'wireguard.publish-peer', 'wireguard.rollback-peer']),
        ['transport' => 'agent', 'name' => 'agent.status', 'input_values' => [], 'resumable' => true],
        ['transport' => 'agent', 'name' => 'firewall.rules', 'bind' => ['desired' => 'input._network.trust.desired',
            'remove' => 'input._network.trust.remove', 'enable' => 'input._network.trust.enable']],
    ],
    'children' => [['kind' => 'node.wireguard_hub_converge', 'node_from' => 'input._network.hub_id']],
    'completion_contract' => ['step' => 'network-result', 'transport' => 'gateway', 'name' => 'node.network-result',
        'result' => ['network_proven' => true], 'children_succeeded' => ['node.wireguard_hub_converge']],
    'result_fields' => ['prepared', 'network_proven', 'public_key', 'retained', 'architecture', 'matches', 'changed', 'outcome', 'error_code', 'failed_step'],
];

$nodeKinds['node.network_rollback'] = [
    'executor' => 'swamp', 'admission' => NodeChildAdmission::class, 'completion' => NodeWireGuardOperationCompletion::class,
    'runs' => [[
        'transport' => 'bootstrap-ssh', 'name' => 'wireguard.rollback-peer',
        'input_fields' => ['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'bootstrap_identity', 'command_input'],
        'bind' => array_combine(['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'bootstrap_identity', 'command_input'],
            array_map(static fn (string $field): string => 'input.'.$field,
                ['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'bootstrap_identity', 'command_input'])),
        'secrets' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity'],
    ]],
    'completion_contract' => ['step' => 'network-rollback', 'transport' => 'bootstrap-ssh', 'name' => 'wireguard.rollback-peer', 'result' => []],
    'result_fields' => ['error_code'],
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
            'ci-runner.host', 'hibernation.prepare-directories', 'wireguard.commit-peer',
        ]),
    ],
    'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'], [
        'node.network_converge', 'node.network_rollback', 'node.role_relocate_source', 'node.role_relocate_target', 'node.converge', 'fleet.cli_converge', 'route.caddy_converge', 'route.private_dns_publish',
        'tool.manager_converge', 'metrics.reconcile', 'metrics.role_converge', 'metrics.role_remove',
        'analytics.role_converge', 'analytics.role_remove', 'websocket.role_converge', 'websocket.role_remove',
    ]),
    'completion_contract' => ['step' => 'role-result', 'transport' => 'gateway', 'name' => 'node.role-result', 'result' => ['role_proven' => true]],
    'compensations' => ['node.network_rollback'], 'cancellation_boundary' => 'role-network',
    'result_fields' => ['role_rollback_required', 'prepared', 'role_proven', 'assignment_id', 'created', 'follow_up', 'failed_step', 'error_code', 'outcome', 'matches',
        'changed', 'configured', 'access_granted', 'version', 'transferred', 'role_wait_until'],
];

foreach (['node.role_add', 'node.role_converge', 'node.role_remove', 'node.role_relocate', 'node.role_relocate_source', 'node.role_relocate_target'] as $nodeRoleKind) {
    $nodeKinds[$nodeRoleKind] = $nodeRoleDefinition;
}

$nodeKinds['node.role_relocate_source']['completion'] = NodeRoleRelocationSourceCompletion::class;

return [...$nodeKinds, ...require __DIR__.'/node-bootstrap-kinds.php'];
