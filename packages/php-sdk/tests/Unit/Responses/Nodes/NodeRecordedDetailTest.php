<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeRoleAssignmentResponse;

it('preserves the named Cluster and complete assignment lifecycle in node detail and JSON', function (): void {
    $assignments = [];
    foreach (['active', 'provisioning', 'failed', 'removing'] as $index => $status) {
        $assignments[] = [
            'id' => $index + 1, 'role' => ['app-dev', 'app-prod', 'database', 'vpn'][$index], 'status' => $status,
            'failed_step' => $status === 'failed' ? 'converge:database' : null,
            'error_code' => $status === 'failed' ? 'node.role_failed' : null,
        ];
    }
    $node = NodeResponse::fromGatewayData([
        'id' => 9, 'name' => 'retained', 'cluster_id' => 7, 'cluster_name' => 'development',
        'roles' => array_column($assignments, 'role'), 'assignments' => $assignments, 'status' => 'failed', 'failed_step' => 'converge:apps', 'error_code' => 'node.enrollment_failed',
    ], 'recorded-request');

    expect($node->roles)->toBe(array_column($assignments, 'role'))
        ->and($node->clusterName)->toBe('development')->and($node->clusterId)->toBe(7)
        ->and($node->assignments)->toHaveCount(4)->and($node->assignments[2])->toBeInstanceOf(NodeRoleAssignmentResponse::class)
        ->and($node->assignments[2]->requestId)->toBe('recorded-request')
        ->and($node->toArray()['assignments'])->toBe($assignments)
        ->and($node->toArray()['cluster_name'])->toBe('development')
        ->and($node->toArray()['failed_step'])->toBe('converge:apps')
        ->and($node->toArray()['error_code'])->toBe('node.enrollment_failed');
});

it('preserves native assignment failed-step labels through node detail and JSON', function (string $failedStep): void {
    $role = [
        'id' => 1, 'role' => 'app-dev', 'status' => 'failed',
        'failed_step' => $failedStep, 'error_code' => 'node_role.convergence_failed',
    ];
    $node = NodeResponse::fromGatewayData([
        'id' => 9, 'name' => 'retained', 'roles' => ['app-dev'], 'assignments' => [$role],
    ], node_role_request_id());

    expect($node->assignments[0]->failedStep)->toBe($failedStep)
        ->and($node->assignments[0]->requestId)->toBe(node_role_request_id())
        ->and($node->toArray()['assignments'])->toBe([$role]);
})->with([
    'native platform guard' => 'converge:Require supported Ubuntu',
    'native role check' => 'converge:Check the role',
    'native punctuation and Unicode' => 'converge:Vérifier le rôle (app-dev)',
    'significant surrounding spaces' => ' converge:Check the role ',
    'maximum length' => str_repeat('a', 128),
]);

it('does not silently erase a malformed assignment list', function (mixed $assignments): void {
    expect(fn (): NodeResponse => NodeResponse::fromGatewayData(['assignments' => $assignments], 'recorded-request'))
        ->toThrow(GatewayApiException::class);
})->with([
    'null container' => [null],
    'scalar container' => ['app-dev'],
    'non-list container' => [['role' => 'app-dev']],
    'retired string assignment' => [['app-dev']],
    'null assignment' => [[null]],
    'non-object assignment' => [[[1, 2]]],
    'missing identity' => [[['role' => 'app-dev', 'status' => 'active']]],
    'invalid identity' => [[['id' => 0, 'role' => 'app-dev', 'status' => 'active']]],
    'invalid role' => [[['id' => 1, 'role' => '../secret', 'status' => 'active']]],
    'invalid status' => [[['id' => 1, 'role' => 'app-dev', 'status' => 'done']]],
    'invalid failure' => [[['id' => 1, 'role' => 'app-dev', 'status' => 'failed', 'failed_step' => ['secret']]]],
]);

it('preserves absent Cluster and role detail without inventing values', function (): void {
    $node = NodeResponse::fromGatewayData(['id' => 9, 'name' => 'sparse'], 'recorded-request');

    expect($node->clusterName)->toBeNull()->and($node->roles)->toBe([])->and($node->assignments)->toBe([])
        ->and($node->toArray()['cluster_name'])->toBeNull()
        ->and($node->toArray()['assignments'])->toBe([]);
});
