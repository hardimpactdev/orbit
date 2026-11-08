<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Nodes\NodeAccessNodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

it('preserves the original positional constructor contract', function (): void {
    $response = new NodeResponse(
        4,
        'app-dev',
        'active',
        '94.237.40.75',
        22,
        'nckrtl',
        '10.44.0.3',
        ['app-dev'],
        '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    );

    expect($response->publicSshHost)
        ->toBe('94.237.40.75')
        ->and($response->user)
        ->toBe('nckrtl')
        ->and($response->wireguardIp)
        ->toBe('10.44.0.3')
        ->and($response->toArray())
        ->toHaveKey('user', 'nckrtl')
        ->and($response->toArray())
        ->not
        ->toHaveKey('ssh_user')
        ->and($response->publicSshPort)
        ->toBe(22)
        ->and($response->roles)
        ->toBe(['app-dev'])
        ->and($response->requestId)
        ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
        ->and($response->platform)
        ->toBeNull()
        ->and($response->architecture)
        ->toBeNull()
        ->and($response->tld)
        ->toBeNull()
        ->and($response->wireguardPublicKey)
        ->toBeNull()
        ->and($response->sshHostFingerprint)
        ->toBeNull()
        ->and($response->failedStep)
        ->toBeNull()
        ->and($response->access)
        ->toBeNull()
        ->and($response->errorCode)
        ->toBeNull();
});

it('preserves the original named constructor contract', function (): void {
    $response = new NodeResponse(
        id: 7,
        name: 'app-prod',
        status: 'active',
        publicSshHost: '85.9.211.193',
        publicSshPort: 22,
        user: 'orbit',
        wireguardIp: '10.44.0.4',
        roles: ['app-prod'],
        requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
    );

    expect($response->name)
        ->toBe('app-prod')
        ->and($response->platform)
        ->toBeNull()
        ->and($response->access)
        ->toBeNull()
        ->and($response->errorCode)
        ->toBeNull();
});

it('preserves recorded native failure labels in the shared Node projection', function (string $step): void {
    $assignment = [
        'id' => 12,
        'role' => 'app-dev',
        'status' => 'failed',
        'failed_step' => $step,
        'error_code' => 'node_role.convergence_failed',
    ];
    $response = NodeResponse::fromGatewayData(node_response_gateway_data([
        'failed_step' => $step,
        'assignments' => [$assignment],
    ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->failedStep)->toBe($step)
        ->and($response->assignments[0]->failedStep)->toBe($step)
        ->and($response->toArray()['assignments'])->toBe([$assignment]);
})->with(['converge:Require supported Ubuntu', 'converge:Check the role']);

it('still rejects unsafe assignment failure labels without exposing them', function (mixed $step): void {
    try {
        NodeResponse::fromGatewayData(node_response_gateway_data([
            'assignments' => [[
                'id' => 12, 'role' => 'app-dev', 'status' => 'failed', 'failed_step' => $step,
            ]],
        ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');
        test()->fail('Unsafe assignment failure label was accepted.');
    } catch (GatewayApiException $exception) {
        expect($exception->getMessage())
            ->toBe('Gateway response contains an invalid node role assignment failed_step.')
            ->not->toContain('step-secret-sentinel');
    }
})->with([
    'wrong type' => [['bad']],
    'empty' => '',
    'overlong' => str_repeat('a', 129),
    'control' => "converge:Require Ubuntu\nstep-secret-sentinel",
    'credential assignment' => 'converge:password=step-secret-sentinel',
    'credential header' => 'converge:Authorization:step-secret-sentinel',
    'bearer credential' => 'converge:Bearer step-secret-sentinel',
]);

it('does not invent an SSH port for malformed gateway data', function (): void {
    $response = NodeResponse::fromGatewayData(
        ['public_ssh_port' => '22'],
        '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    );

    expect($response->publicSshPort)->toBe(0);
});

it('defaults managed user to orbit when gateway user is missing, invalid, or empty', function (): void {
    $missingUser = node_response_gateway_data();
    unset($missingUser['user']);

    foreach ([
        $missingUser,
        node_response_gateway_data(['user' => 123]),
        node_response_gateway_data(['user' => '']),
    ] as $data) {
        $response = NodeResponse::fromGatewayData(
            $data,
            '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        );

        expect($response->user)->toBe('orbit');
    }
});

it('preserves a non-empty managed user from gateway data', function (): void {
    $response = NodeResponse::fromGatewayData(
        node_response_gateway_data(['user' => 'deploy-user']),
        '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    );

    expect($response->user)->toBe('deploy-user');
});

it('omits access when the gateway does not send an access key', function (): void {
    $response = NodeResponse::fromGatewayData(node_response_gateway_data(), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->access)
        ->toBeNull()
        ->and($response->toArray())
        ->not->toHaveKey('access');
});

it('preserves valid access data in both directions', function (): void {
    $response = NodeResponse::fromGatewayData(node_response_gateway_data([
        'access' => [
            'can_access' => [
                ['id' => 3, 'name' => 'gateway'],
            ],
            'accessible_by' => [
                ['id' => 7, 'name' => 'maintainer'],
            ],
        ],
    ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->access)
        ->toBeInstanceOf(NodeAccessResponse::class)
        ->and($response->access?->canAccess[0])
        ->toBeInstanceOf(NodeAccessNodeResponse::class)
        ->and($response->access?->accessibleBy[0])
        ->toBeInstanceOf(NodeAccessNodeResponse::class)
        ->and($response->toArray()['access'] ?? null)
        ->toBe([
            'can_access' => [
                ['id' => 3, 'name' => 'gateway'],
            ],
            'accessible_by' => [
                ['id' => 7, 'name' => 'maintainer'],
            ],
        ]);
});

it('drops malformed access list entries instead of inventing synthetic nodes', function (): void {
    $response = NodeResponse::fromGatewayData(node_response_gateway_data([
        'access' => [
            'can_access' => [
                ['id' => 3, 'name' => 'gateway'],
                ['id' => 0, 'name' => 'bad-id'],
                ['id' => 5, 'name' => ''],
            ],
            'accessible_by' => [
                ['id' => '7', 'name' => 'bad-type'],
                ['id' => 8, 'name' => 'maintainer'],
            ],
        ],
    ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->access?->toArray())
        ->toBe([
            'can_access' => [
                ['id' => 3, 'name' => 'gateway'],
            ],
            'accessible_by' => [
                ['id' => 8, 'name' => 'maintainer'],
            ],
        ]);
});

it('keeps real access in node collections while stripping nested request ids', function (): void {
    $response = new NodesResponse([
        new NodeResponse(
            id: 4,
            name: 'app-dev',
            status: 'active',
            publicSshHost: '94.237.40.75',
            publicSshPort: 22,
            user: 'orbit',
            wireguardIp: '10.44.0.3',
            roles: ['app-dev'],
            requestId: '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
            clusterId: 3,
            lanIp: '10.0.0.3',
            access: new NodeAccessResponse(
                canAccess: [new NodeAccessNodeResponse(id: 3, name: 'gateway')],
                accessibleBy: [new NodeAccessNodeResponse(id: 7, name: 'maintainer')],
            ),
        ),
    ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');

    expect($response->toArray())
        ->toBe([
            'nodes' => [[
                ...node_response_public_data(),
                'access' => [
                    'can_access' => [
                        ['id' => 3, 'name' => 'gateway'],
                    ],
                    'accessible_by' => [
                        ['id' => 7, 'name' => 'maintainer'],
                    ],
                ],
            ]],
            'request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        ]);
});

it('preserves recorded native failure labels through the Node response', function (string $label): void {
    $assignment = [
        'id' => 12,
        'role' => 'app-dev',
        'status' => 'failed',
        'failed_step' => $label,
        'error_code' => 'node_role.convergence_failed',
    ];
    $response = NodeResponse::fromGatewayData(node_response_gateway_data([
        'assignments' => [$assignment],
        'failed_step' => $label,
        'error_code' => 'node_role.convergence_failed',
    ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->failedStep)->toBe($label)
        ->and($response->toArray()['failed_step'])->toBe($label)
        ->and($response->roles)->toBe(['app-dev'])
        ->and($response->assignments[0]->failedStep)->toBe($label)
        ->and($response->toArray()['assignments'])->toBe([$assignment])
        ->and($response->id)->toBe(4)
        ->and($response->name)->toBe('app-dev');
})->with([
    'owner reproduction' => ['converge:Require supported Ubuntu'],
    'role-list reproduction' => ['converge:Check the role'],
]);

it('uses only canonical node network fields', function (): void {
    $response = NodeResponse::fromGatewayData(node_response_gateway_data([
        'wireguard_address' => '10.44.0.99',
    ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->wireguardIp)
        ->toBe('10.44.0.3')
        ->and($response->clusterId)
        ->toBe(3)
        ->and($response->lanIp)
        ->toBe('10.0.0.3')
        ->and($response->toArray())
        ->not->toHaveKey('wireguard_address');
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function node_response_gateway_data(array $overrides = []): array
{
    return array_replace([
        'id' => 4,
        'cluster_id' => 3,
        'name' => 'app-dev',
        'status' => 'active',
        'public_ssh_host' => '94.237.40.75',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
        'lan_ip' => '10.0.0.3',
        'roles' => ['app-dev'],
    ], $overrides);
}

/** @return array<string, int|string|list<string>|null> */
function node_response_public_data(): array
{
    return [
        'id' => 4,
        'cluster_id' => 3,
        'name' => 'app-dev',
        'status' => 'active',
        'platform' => null,
        'architecture' => null,
        'tld' => null,
        'public_ssh_host' => '94.237.40.75',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
        'lan_ip' => '10.0.0.3',
        'wireguard_public_key' => null,
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => ['app-dev'],
        'settings' => null,
        'updating' => null,
        'agent' => null,
        'cluster_name' => null,
        'assignments' => [],
    ];
}

it('preserves the shared assignment projection and Cluster name in Node collections', function (): void {
    $assignments = [];
    foreach (['active', 'provisioning', 'failed', 'removing'] as $index => $status) {
        $assignments[] = ['id' => $index + 1, 'role' => 'app-dev', 'status' => $status,
            'failed_step' => $status === 'failed' ? 'ansible:converge' : null,
            'error_code' => $status === 'failed' ? 'role.converge_failed' : null];
    }
    $node = NodeResponse::fromGatewayData(node_response_gateway_data([
        'cluster_name' => 'development', 'assignments' => $assignments,
    ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    $list = new NodesResponse([$node], $node->requestId);

    expect($node->clusterName)->toBe('development')
        ->and($node->assignments)->toHaveCount(4)
        ->and($list->toArray()['nodes'][0]['cluster_name'])->toBe('development')
        ->and($list->toArray()['nodes'][0]['cluster_id'])->toBe(3)
        ->and($list->toArray()['nodes'][0]['assignments'])->toBe($assignments);
});

it('reads the update in progress and ignores a malformed one', function (mixed $updating, ?array $expected): void {
    $response = NodeResponse::fromGatewayData(['id' => 4, 'name' => 'gateway', 'updating' => $updating], 'request');

    expect($response->updating?->toArray())->toBe($expected)
        ->and($response->toArray()['updating'])->toBe($expected);
})->with([
    'gateway release' => [
        ['kind' => 'gateway_release', 'since' => '2026-10-08T12:00:00+00:00', 'release' => 31],
        ['kind' => 'gateway_release', 'since' => '2026-10-08T12:00:00+00:00', 'release' => 31],
    ],
    'absent' => [null, null],
    'unknown kind' => [['kind' => 'reboot', 'since' => '2026-10-08T12:00:00+00:00'], null],
    'missing since' => [['kind' => 'gateway_release', 'release' => 3], null],
    'retired fleet rollout kind' => [['kind' => 'fleet_rollout', 'since' => '2026-10-08T12:00:00+00:00', 'rollout' => 3], null],
]);
