<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Nodes\NodeRoleAssignmentResponse;
use Orbit\Sdk\Responses\Nodes\NodeRolesResponse;

describe('node role assignment response transport', function (): void {
    it('preserves every Ingress assignment retry replacement and removal lifecycle result', function (): void {
        $assignments = array_map(
            static fn (array $data): array => NodeRoleAssignmentResponse::fromGatewayData(
                $data,
                node_role_request_id(),
            )->toArray(),
            [
                [
                    'id' => 41,
                    'role' => 'ingress',
                    'status' => 'provisioning',
                    'failed_step' => null,
                    'error_code' => null,
                ],
                [
                    'id' => 41,
                    'role' => 'ingress',
                    'status' => 'failed',
                    'failed_step' => 'converge:baseline',
                    'error_code' => 'node_role.convergence_failed',
                ],
                [
                    'id' => 41,
                    'role' => 'ingress',
                    'status' => 'active',
                    'failed_step' => null,
                    'error_code' => null,
                ],
                [
                    'id' => 41,
                    'role' => 'ingress',
                    'status' => 'removing',
                    'failed_step' => null,
                    'error_code' => null,
                ],
            ],
        );
        expect(array_column($assignments, 'status'))
            ->toBe(['provisioning', 'failed', 'active', 'removing'])
            ->and(array_unique(array_column($assignments, 'role')))
            ->toBe(['ingress']);
    });

    it('preserves recorded native failure labels verbatim', function (?string $label): void {
        $assignment = NodeRoleAssignmentResponse::fromGatewayData([
            'id' => 41,
            'role' => 'app-dev',
            'status' => 'failed',
            'failed_step' => $label,
            'error_code' => 'node_role.convergence_failed',
        ], node_role_request_id());

        expect($assignment->failedStep)->toBe($label)
            ->and($assignment->toArray()['failed_step'])->toBe($label)
            ->and($assignment->requestId)->toBe(node_role_request_id());
    })->with([
        'owner reproduction' => ['converge:Require supported Ubuntu'],
        'role-list reproduction' => ['converge:Check the role'],
        'removal label' => ['remove:Remove from the source'],
        'punctuation' => ['converge:Check Ubuntu (24.04) / x86_64'],
        'unicode' => ['converge:Préparer Ubuntu'],
        'surrounding spaces' => ['converge: Check the role '],
        'maximum byte length' => [str_repeat('A', 128)],
        'unset' => [null],
    ]);

    it('rejects malformed and unsafe failure labels', function (mixed $label): void {
        expect(fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
            'id' => 41,
            'role' => 'app-dev',
            'status' => 'failed',
            'failed_step' => $label,
        ], node_role_request_id()))->toThrow(
            GatewayApiException::class,
            'Gateway response contains an invalid node role assignment failed_step.',
        );
    })->with([
        'empty' => [''],
        'over length' => [str_repeat('A', 129)],
        'multibyte over byte limit' => [str_repeat('é', 65)],
        'array' => [[]],
        'integer' => [1],
        'boolean' => [false],
        'malformed UTF-8' => ["converge:Check \xFF"],
        'Unicode control' => ["converge:Check\u{009B}Ubuntu"],
        'Unicode formatting' => ["converge:Check\u{202E}Ubuntu"],
        'credential assignment' => ['converge:password=step-secret-sentinel'],
        'credential header' => ['converge:Authorization:step-secret-sentinel'],
        'bearer credential' => ['converge:Bearer step-secret-sentinel'],
        ...array_map(
            static fn (int $byte): array => ['converge:Check'.chr($byte).'Ubuntu'],
            [...range(0, 31), 127],
        ),
    ]);

    it('maps role list responses with the stable request id', function (): void {
        $list = new NodeRolesResponse(
            assignments: [
                NodeRoleAssignmentResponse::fromGatewayData([
                    'id' => 34,
                    'role' => 'app-dev',
                    'status' => 'provisioning',
                    'failed_step' => null,
                    'error_code' => null,
                ], node_role_request_id()),
            ],
            requestId: node_role_request_id(),
        );
        expect($list->toArray())
            ->toBe([
                'assignments' => [[
                    'id' => 34,
                    'role' => 'app-dev',
                    'status' => 'provisioning',
                    'failed_step' => null,
                    'error_code' => null,
                ]],
                'request_id' => node_role_request_id(),
            ]);
    });

    it('accepts provisioning and removing assignment statuses', function (): void {
        $provisioning = NodeRoleAssignmentResponse::fromGatewayData([
            'id' => 34,
            'role' => 'app-dev',
            'status' => 'provisioning',
            'failed_step' => null,
            'error_code' => null,
        ], node_role_request_id());
        $removing = NodeRoleAssignmentResponse::fromGatewayData([
            'id' => 35,
            'role' => 'app-prod',
            'status' => 'removing',
            'failed_step' => 'remove:firewall',
            'error_code' => 'node_role.remove_failed',
        ], node_role_request_id());

        expect($provisioning->status)
            ->toBe('provisioning')
            ->and($removing->status)
            ->toBe('removing')
            ->and($removing->failedStep)
            ->toBe('remove:firewall');
    });

    it('rejects malformed ids unsafe roles unsupported statuses and invalid failure fields', function (): void {
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => '34',
                'role' => 'app-dev',
                'status' => 'active',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment id.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => '',
                'status' => 'active',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment role.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'token=secret',
                'status' => 'active',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment role.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'future-role',
                'status' => 'draining',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment status.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'app-dev',
                'status' => 'failed',
                'failed_step' => ['bad'],
                'error_code' => null,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment failed_step.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'app-dev',
                'status' => 'failed',
                'failed_step' => 'remove:baseline',
                'error_code' => ['bad'],
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment error_code.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'app-dev',
                'status' => 'failed',
                'failed_step' => "converge:caddy config\r\nx-token: ".node_role_secret('failed-step-control'),
                'error_code' => null,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment failed_step.',
            rejected: ["converge:caddy config\r\nx-token: ".node_role_secret('failed-step-control')],
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'app-dev',
                'status' => 'failed',
                'failed_step' => str_repeat('a', times: 129),
                'error_code' => null,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment failed_step.',
            rejected: [str_repeat('a', times: 129)],
        );
    });
});
