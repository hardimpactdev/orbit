<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorIssue;
use App\Enums\DriftKind;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorIssueIdentityResolver;
use App\Services\Doctor\DoctorOutcomeReconciler;

it('removes only issues resolved by successful matching action receipts', function (): void {
    $reconciler = doctor_outcome_reconciler();
    $resolved = doctor_outcome_issue(family: 'node', code: 'node.dns_mapping_mismatch');
    $remaining = doctor_outcome_issue(family: 'node', code: 'node.websocket.backend_cert_missing');

    $issues = $reconciler->remainingIssues([$resolved, $remaining], [
        [
            'family' => 'node',
            'key' => 'node.dns_mapping_mismatch',
            'status' => 'completed',
        ],
        [
            'family' => 'node',
            'key' => 'node.websocket.backend_cert_missing',
            'status' => 'failed',
        ],
    ]);

    expect($issues)->toBe([$remaining]);
});

it('matches database connection action receipts by their exact target', function (): void {
    $reconciler = doctor_outcome_reconciler();
    $resolved = doctor_outcome_issue(family: 'database_connection', code: 'database_connection.env_missing', detail: [
        'target_type' => 'instance',
        'target_id' => '21',
        'env_prefix' => 'REPORTING',
    ]);
    $remaining = doctor_outcome_issue(family: 'database_connection', code: 'database_connection.env_mismatch', detail: [
        'target_type' => 'instance',
        'target_id' => '22',
        'env_prefix' => 'REPORTING',
    ]);

    $issues = $reconciler->remainingIssues([$resolved, $remaining], [[
        'family' => 'database_connection',
        'key' => 'database_connection.target_missing',
        'status' => 'completed',
        'details' => [
            'target_type' => 'instance',
            'target_id' => '21',
            'env_prefix' => 'REPORTING',
        ],
    ]]);

    expect($issues)->toBe([$remaining]);
});

it('fails a completed DNS tool action when allowlisted DNS drift remains', function (): void {
    $reconciler = doctor_outcome_reconciler();
    $remaining = doctor_outcome_issue(family: 'tool', code: 'tool.dns_port_not_listening', detail: [
        'port' => 53,
    ]);
    $action = [
        'family' => 'tool',
        'node' => 'gateway',
        'key' => 'tool.dns_container_missing',
        'status' => 'completed',
        'summary' => 'Restored DNS runtime.',
        'details' => ['attempt' => 1],
    ];

    $actions = $reconciler->annotateRestoreActionsWithRemainingDrift([$action], [$remaining]);

    expect($actions)->toBe([[
        ...$action,
        'status' => 'failed',
        'summary' => 'DNS runtime restore verification failed.',
        'details' => [
            'attempt' => 1,
            'port' => 53,
            'operation' => 'verify tool.dns_port_not_listening',
            'error' => 'DNS runtime drift [tool.dns_port_not_listening] remained after restore.',
        ],
    ]]);
});

it('uses the node fence when node DNS drift remains', function (): void {
    $reconciler = doctor_outcome_reconciler();
    $remaining = doctor_outcome_issue(
        family: 'node',
        code: 'node.dns_mapping_mismatch',
        detail: ['address' => '10.6.0.7'],
        node: 'node-1',
    );
    $matching = [
        'family' => 'node',
        'node' => 'node-1',
        'key' => 'node.dns_mapping_mismatch',
        'status' => 'completed',
        'details' => [],
    ];
    $otherNode = [
        ...$matching,
        'node' => 'node-2',
    ];

    $actions = $reconciler->annotateRestoreActionsWithRemainingDrift(
        [$matching, $otherNode],
        [$remaining],
    );

    expect($actions[0])
        ->toMatchArray([
            'status' => 'failed',
            'summary' => "Node DNS restore verification failed on node 'node-1'.",
            'details' => [
                'address' => '10.6.0.7',
                'node' => 'node-1',
                'operation' => 'verify node.dns_mapping_mismatch',
                'error' => "Node DNS drift remained after restore on node 'node-1'.",
            ],
        ])
        ->and($actions[1])
        ->toBe($otherNode);
});

it('matches a proxy action route to the remaining issue domain', function (): void {
    $reconciler = doctor_outcome_reconciler();
    $remaining = doctor_outcome_issue(
        family: 'proxy',
        code: 'proxy.tls_missing',
        detail: [
            'domain' => 'demo.orbit.test',
            'certificate' => 'missing',
        ],
        node: 'router',
    );
    $action = [
        'family' => 'proxy',
        'node' => 'another-router',
        'key' => 'proxy.route_missing',
        'status' => 'completed',
        'details' => ['route' => 'demo.orbit.test'],
    ];

    $actions = $reconciler->annotateRestoreActionsWithRemainingDrift([$action], [$remaining]);

    expect($actions[0])
        ->toMatchArray([
            'status' => 'failed',
            'summary' => "Proxy restore verification failed on node 'router' during 'verify proxy.tls_missing'.",
            'details' => [
                'route' => 'demo.orbit.test',
                'domain' => 'demo.orbit.test',
                'certificate' => 'missing',
                'node' => 'router',
                'operation' => 'verify proxy.tls_missing',
                'error' => "Drift remained after repair on node 'router' during 'verify proxy.tls_missing'.",
            ],
        ]);
});

it('requires exact websocket issue identity and matching node', function (): void {
    $reconciler = doctor_outcome_reconciler();
    $remaining = doctor_outcome_issue(
        family: 'node',
        code: 'node.websocket.backend_cert_missing',
        detail: ['path' => '/etc/orbit/websocket.crt'],
        node: 'websocket-1',
    );
    $matching = [
        'family' => 'node',
        'node' => 'websocket-1',
        'code' => 'node.websocket.backend_cert_missing',
        'key' => 'node.websocket.backend_cert_missing',
        'status' => 'completed',
        'details' => [],
    ];
    $wrongNode = [
        ...$matching,
        'node' => 'websocket-2',
    ];
    $notCompleted = [
        ...$matching,
        'status' => 'updated',
    ];

    $actions = $reconciler->annotateRestoreActionsWithRemainingDrift(
        [$matching, $wrongNode, $notCompleted],
        [$remaining],
    );

    expect($actions[0])
        ->toMatchArray([
            'status' => 'failed',
            'summary' => "WebSocket restore verification failed on node 'websocket-1'.",
            'details' => [
                'path' => '/etc/orbit/websocket.crt',
                'node' => 'websocket-1',
                'operation' => 'verify node.websocket.backend_cert_missing',
                'error' => "WebSocket drift 'node.websocket.backend_cert_missing' remained after restore on node 'websocket-1'.",
            ],
        ])
        ->and($actions[1])
        ->toBe($wrongNode)
        ->and($actions[2])
        ->toBe($notCompleted);
});

function doctor_outcome_reconciler(): DoctorOutcomeReconciler
{
    expect(class_exists(DoctorOutcomeReconciler::class))->toBeTrue();

    return new DoctorOutcomeReconciler;
}

/**
 * @param  array<string, mixed>  $detail
 */
function doctor_outcome_issue(
    string $family,
    string $code,
    array $detail = [],
    ?string $node = 'node-1',
): DoctorIssue {
    return new DoctorIssueFactory(new DoctorIssueIdentityResolver)->fromArray([
        'family' => $family,
        'code' => $code,
        'key' => $code,
        'node' => $node,
        'kind' => DriftKind::Divergent->value,
        'summary' => $code,
        'detail' => $detail,
    ]);
}
