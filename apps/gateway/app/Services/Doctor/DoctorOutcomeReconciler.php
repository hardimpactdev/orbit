<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;

/** @mago-expect lint:cyclomatic-complexity */
final class DoctorOutcomeReconciler
{
    private const array VERIFIED_WEBSOCKET_RESTORE_KEYS = [
        'node.websocket.backend_cert_missing',
        'node.websocket.bind_public_interface',
    ];

    private const array VERIFIED_DNS_TOOL_RESTORE_KEYS = [
        'tool.dns_container_missing',
        'tool.dns_port_not_listening',
        'tool.dns_base_config_mismatch',
        'tool.dns_client_dns_drift',
        'tool.dns_forwarding_missing',
    ];

    /**
     * @param  list<DoctorIssue>  $issues
     * @param  list<array<string, mixed>>  $actions
     * @return list<DoctorIssue>
     */
    public function remainingIssues(array $issues, array $actions): array
    {
        $resolvedIssueIds = array_filter(array_map(
            static fn (array $action): ?string => in_array(
                $action['status'] ?? null,
                ['completed', 'created', 'updated'],
                strict: true,
            )
                    ? DoctorIssueResolutionId::forAction($action)
                    : null,
            $actions,
        ));
        $resolvedDatabaseTargets = array_values(array_filter(array_map(
            DoctorIssueResolutionId::databaseTargetForAction(...),
            $actions,
        )));

        return array_values(array_filter(
            $issues,
            static fn (DoctorIssue $issue): bool => (
                ! in_array(
                    DoctorIssueResolutionId::forIssue($issue),
                    $resolvedIssueIds,
                    strict: true,
                )
                && ! in_array(
                    DoctorIssueResolutionId::databaseTargetForIssue($issue),
                    $resolvedDatabaseTargets,
                    strict: true,
                )
            ),
        ));
    }

    /**
     * Enrich restore action receipts when family-specific re-probe still finds
     * matching drift. Does not filter issues; fresh observation remains
     * authoritative in the report coordinator.
     *
     * @param  list<array<string, mixed>>  $actions
     * @param  list<DoctorIssue>  $remainingIssues
     * @return list<array<string, mixed>>
     */
    public function annotateRestoreActionsWithRemainingDrift(
        array $actions,
        array $remainingIssues,
    ): array {
        $annotatedActions = [];

        foreach ($actions as $action) {
            $annotatedActions[] = $this->verifyCompletedDnsToolAction(
                $this->verifyCompletedWebSocketAction(
                    $this->verifyCompletedNodeDnsAction(
                        $this->verifyCompletedProxyAction($action, $remainingIssues),
                        $remainingIssues,
                    ),
                    $remainingIssues,
                ),
                $remainingIssues,
            );
        }

        return $annotatedActions;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<DoctorIssue>  $remainingIssues
     * @return array<string, mixed>
     */
    private function verifyCompletedDnsToolAction(array $action, array $remainingIssues): array
    {
        if (
            ($action['status'] ?? null) !== 'completed'
            || ($action['family'] ?? null) !== 'tool'
            || ! in_array(
                $action['key'] ?? null,
                self::VERIFIED_DNS_TOOL_RESTORE_KEYS,
                strict: true,
            )
        ) {
            return $action;
        }

        $remainingIssue = collect($remainingIssues)->first(
            static fn (DoctorIssue $issue): bool => (
                $issue->family === 'tool'
                && in_array($issue->key, self::VERIFIED_DNS_TOOL_RESTORE_KEYS, strict: true)
            ),
        );

        if (! $remainingIssue instanceof DoctorIssue) {
            return $action;
        }

        $key = $remainingIssue->key;
        $issueDetail = $remainingIssue->detail;
        $details = is_array($action['details'] ?? null) ? $action['details'] : [];

        return [
            ...$action,
            'status' => 'failed',
            'summary' => 'DNS runtime restore verification failed.',
            'details' => [
                ...$details,
                ...$issueDetail,
                'operation' => "verify {$key}",
                'error' => "DNS runtime drift [{$key}] remained after restore.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<DoctorIssue>  $remainingIssues
     * @return array<string, mixed>
     */
    private function verifyCompletedNodeDnsAction(array $action, array $remainingIssues): array
    {
        if (
            ($action['status'] ?? null) !== 'completed'
            || ($action['family'] ?? null) !== 'node'
            || ($action['key'] ?? null) !== 'node.dns_mapping_mismatch'
        ) {
            return $action;
        }

        $remainingIssue = collect($remainingIssues)->first(
            fn (DoctorIssue $issue): bool => (
                $issue->family === 'node'
                && $issue->key === 'node.dns_mapping_mismatch'
                && (
                    $this->stringValue($action, 'node') === null
                    || $this->stringValue($action, 'node') === $issue->node
                )
            ),
        );

        if (! $remainingIssue instanceof DoctorIssue) {
            return $action;
        }

        $node = $remainingIssue->node ?? $this->stringValue($action, 'node') ?? 'unknown';
        $issueDetail = $remainingIssue->detail;
        $details = is_array($action['details'] ?? null) ? $action['details'] : [];

        return [
            ...$action,
            'status' => 'failed',
            'summary' => "Node DNS restore verification failed on node '{$node}'.",
            'details' => [
                ...$details,
                ...$issueDetail,
                'node' => $node,
                'operation' => 'verify node.dns_mapping_mismatch',
                'error' => "Node DNS drift remained after restore on node '{$node}'.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<DoctorIssue>  $remainingIssues
     * @return array<string, mixed>
     */
    private function verifyCompletedProxyAction(array $action, array $remainingIssues): array
    {
        if (
            ($action['status'] ?? null) !== 'completed'
            || ($action['family'] ?? null) !== 'proxy'
        ) {
            return $action;
        }

        $remainingIssue = collect($remainingIssues)->first(
            fn (DoctorIssue $issue): bool => $this->actionMatchesRemainingIssue($action, $issue),
        );

        if (! $remainingIssue instanceof DoctorIssue) {
            return $action;
        }

        return $this->failedProxyAction($action, $remainingIssue);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<DoctorIssue>  $remainingIssues
     * @return array<string, mixed>
     */
    private function verifyCompletedWebSocketAction(array $action, array $remainingIssues): array
    {
        if (
            ($action['status'] ?? null) !== 'completed'
            || ($action['family'] ?? null) !== 'node'
            || ! in_array(
                $action['key'] ?? null,
                self::VERIFIED_WEBSOCKET_RESTORE_KEYS,
                strict: true,
            )
        ) {
            return $action;
        }

        $remainingIssue = collect($remainingIssues)->first(
            fn (DoctorIssue $issue): bool => (
                $issue->family === 'node'
                && (
                    $this->stringValue($action, 'node') === null
                    || $this->stringValue($action, 'node') === $issue->node
                )
                && DoctorIssueResolutionId::forAction($action) === DoctorIssueResolutionId::forIssue($issue)
            ),
        );

        if (! $remainingIssue instanceof DoctorIssue) {
            return $action;
        }

        $node = $remainingIssue->node ?? $this->stringValue($action, 'node') ?? 'unknown';
        $key = $remainingIssue->key;
        $issueDetail = $remainingIssue->detail;
        $details = is_array($action['details'] ?? null) ? $action['details'] : [];

        return [
            ...$action,
            'status' => 'failed',
            'summary' => "WebSocket restore verification failed on node '{$node}'.",
            'details' => [
                ...$details,
                ...$issueDetail,
                'node' => $node,
                'operation' => "verify {$key}",
                'error' => "WebSocket drift '{$key}' remained after restore on node '{$node}'.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function failedProxyAction(array $action, DoctorIssue $remainingIssue): array
    {
        $node = $remainingIssue->node ?? $this->stringValue($action, 'node') ?? 'unknown';
        $key = $remainingIssue->key;
        $operation = "verify {$key}";
        $issueDetail = $remainingIssue->detail;
        $details = is_array($action['details'] ?? null) ? $action['details'] : [];

        return [
            ...$action,
            'status' => 'failed',
            'summary' => "Proxy restore verification failed on node '{$node}' during '{$operation}'.",
            'details' => [
                ...$details,
                ...$issueDetail,
                'node' => $node,
                'operation' => $operation,
                'error' => "Drift remained after repair on node '{$node}' during '{$operation}'.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function actionMatchesRemainingIssue(array $action, DoctorIssue $issue): bool
    {
        if (($action['family'] ?? null) !== 'proxy' || $issue->family !== 'proxy') {
            return false;
        }

        $actionDetails = is_array($action['details'] ?? null) ? $action['details'] : [];
        $issueDetail = $issue->detail;
        $actionDomain = is_string($actionDetails['route'] ?? null) ? $actionDetails['route'] : null;
        $issueDomain = is_string($issueDetail['domain'] ?? null) ? $issueDetail['domain'] : null;

        if ($actionDomain !== null) {
            return $actionDomain === $issueDomain;
        }

        $actionNode = is_string($action['node'] ?? null) ? $action['node'] : null;
        $issueNode = $issue->node;

        return (
            ($actionNode === null || $actionNode === $issueNode)
            && DoctorIssueResolutionId::forAction($action) === DoctorIssueResolutionId::forIssue($issue)
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function stringValue(array $item, string $key): ?string
    {
        $value = $item[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
