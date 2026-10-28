<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeAccessOperationCompletion;
use App\Domain\Nodes\NodeAccessOperationExecution;
use App\Domain\Nodes\NodeBootstrapOperationCompletion;
use App\Domain\Nodes\NodeFootprintOperationCompletion;
use App\Domain\Nodes\NodePhpPackagesOperationCompletion;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Nodes\NodeWireGuardOperationCompletion;
use App\Domain\Nodes\NodeWireGuardOperationExecution;
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

return [
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
        'executor' => 'swamp',
        'completion' => NodeFootprintOperationCompletion::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'node.prepare-footprint', 'resumable' => true, 'input_fields' => ['force_artifacts', 'first_visit']],
            ['transport' => 'gateway', 'name' => 'node.prepare-agent-configuration', 'resumable' => true, 'input_values' => []],
            ['transport' => 'gateway', 'name' => 'node.footprint-result', 'resumable' => true, 'input_values' => []],
            ['transport' => 'node-agent', 'name' => 'agent.install', 'input_values' => []],
            [...$bound('agent.configure', 'agent', ['secret_hash', 'configuration', 'certificate', 'unit', 'checkouts', 'binary_changed']),
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
            'input_fields' => ['bootstrap_host', 'bootstrap_port', 'bootstrap_fingerprint', 'bootstrap_user', 'bootstrap_identity', 'command_input'],
            'input_values' => [],
            'secrets' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity'],
            'input_schema' => BootstrapSshOperationTransport::inputSchema('agent.install'),
            'bind' => [
                'bootstrap_host' => 'input.bootstrap_host', 'bootstrap_port' => 'input.bootstrap_port',
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
