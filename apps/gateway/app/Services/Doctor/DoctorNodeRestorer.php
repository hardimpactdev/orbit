<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Services\Nodes\NodesProbe;
use Throwable;

final readonly class DoctorNodeRestorer
{
    public function __construct(
        private NodesProbe $nodesProbe,
        private DoctorIssueNodeResolver $issueNodeResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function apply(Node $fallbackNode, DoctorIssue $issue): array
    {
        $node = $this->issueNodeResolver->resolve($issue) ?? $fallbackNode;
        $key = $issue->key;
        $code = $issue->code;

        try {
            $this->nodesProbe->reconcile($node, new DriftEntry(
                family: 'node',
                key: $key,
                kind: $issue->kind,
                summary: $issue->summary,
                detail: $issue->detail,
            ));
        } catch (Throwable $exception) {
            return [
                'family' => 'node',
                'node' => $node->name,
                'code' => $code,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$code}.",
                'details' => [
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'node',
            'node' => $node->name,
            'code' => $code,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => $issue->summary !== '' ? $issue->summary : "Fixed {$code}.",
            'details' => $issue->detail,
        ];
    }
}
