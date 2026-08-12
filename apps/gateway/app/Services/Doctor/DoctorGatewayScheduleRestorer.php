<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Schedules\SchedulesFixer;
use Throwable;

final readonly class DoctorGatewayScheduleRestorer
{
    public function __construct(
        private SchedulesFixer $schedulesFixer,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public function apply(
        Node $fallbackNode,
        string $key,
        array $detail,
        DoctorIssue $issue,
        ?Schedule $schedule,
    ): array {
        $gatewayNode = $this->gatewayNode() ?? $this->nodeFromIssue($issue) ?? $fallbackNode;

        try {
            $fixed = $this->schedulesFixer->fixGateway(
                $gatewayNode,
                new DriftEntry(
                    family: 'schedule',
                    key: $key,
                    kind: $issue->kind,
                    summary: $issue->summary,
                    detail: $detail,
                ),
                $schedule,
            );

            return $fixed ?? [
                'family' => 'schedule',
                'node' => $gatewayNode->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'skipped',
                'summary' => "No restore action is registered for {$key}.",
                'details' => [
                    'reason' => 'mode_not_supported',
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'family' => 'schedule',
                'node' => $gatewayNode->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$key}.",
                'details' => [
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function nodeFromIssue(DoctorIssue $issue): ?Node
    {
        if ($issue->node === null) {
            return null;
        }

        $node = Node::query()->where('name', $issue->node)->first();

        return $node instanceof Node ? $node : null;
    }

    private function gatewayNode(): ?Node
    {
        $node = $this->nodeRoleAssignments->activeGatewayNodeQuery()->first();

        return $node instanceof Node ? $node : null;
    }
}
