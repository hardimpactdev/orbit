<?php

declare(strict_types=1);

/** Prove metadata only through the unchanged configured Gateway run token. */
function proveConfiguredManifestPolicy(callable $rpc, array $policy): array
{
    $identity = $rpc('access.can-i', []);
    if (($identity['principal'] ?? null) !== 'user:orbit-gateway') {
        throw new RuntimeException('configured_principal_changed');
    }
    $checks = [];
    foreach ($policy['grants'] as $grant) {
        foreach ($grant['actions'] as $action) {
            $resource = $grant['resource']['kind'].':'.$grant['resource']['pattern'];
            $matches = array_values(array_filter($identity['decisions'] ?? [], static fn (array $row): bool =>
                ($row['grantId'] ?? null) === $grant['id']
                && ($row['resource'] ?? null) === $resource
                && ($row['action'] ?? null) === $action
                && ($row['effect'] ?? null) === 'allow'
                && ($row['condition'] ?? null) === ($grant['condition'] ?? null)
            ));
            if (count($matches) !== 1) {
                throw new RuntimeException('effective_exact_manifest_scope_absent');
            }
            $checks[] = $matches[0];
        }
    }
    $denials = [['admin', 'access:*'], ['run', 'workflow:platform-unrelated-denied']];
    foreach ($policy['registered_workflows'] as $expected) {
        foreach (['run', 'read'] as $action) {
            $resource = 'workflow:'.$expected['name'];
            $data = $rpc('access.can-i', ['action' => $action, 'resource' => $resource]);
            $effects = array_column($data['decisions'] ?? [], 'effect');
            if (($data['principal'] ?? null) !== 'user:orbit-gateway'
                || !in_array('allow', $effects, true) || in_array('deny', $effects, true)) {
                throw new RuntimeException('effective_workflow_permission_denied');
            }
            $checks[] = ['action' => $action, 'resource' => $resource, 'allowed' => true];
        }
        $data = $rpc('workflow.get', ['workflowIdOrName' => $expected['name']]);
        $workflow = $data['workflow'] ?? $data;
        if (($workflow['id'] ?? null) !== $expected['id'] || ($workflow['name'] ?? null) !== $expected['name']) {
            throw new RuntimeException('configured_token_workflow_uuid_unproven');
        }
        $denials[] = ['write', 'workflow:'.$expected['name']];
    }
    foreach ($denials as [$action, $resource]) {
        $data = $rpc('access.can-i', ['action' => $action, 'resource' => $resource]);
        if (($data['principal'] ?? null) !== 'user:orbit-gateway'
            || in_array('allow', array_column($data['decisions'] ?? [], 'effect'), true)) {
            throw new RuntimeException('unexpected_broad_permission');
        }
        $checks[] = ['action' => $action, 'resource' => $resource, 'allowed' => false];
    }
    return ['at' => gmdate('c'), 'principal' => 'user:orbit-gateway', 'verified' => true,
        'checks' => $checks, 'registered_workflows' => $policy['registered_workflows'],
        'token_unchanged' => true, 'values_read' => false];
}
