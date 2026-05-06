<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Services\Nodes\NodesProbe;

final readonly class DoctorReportRunner
{
    private const array SUPPORTED_FAMILIES = ['node'];

    public function __construct(
        private NodesProbe $nodesProbe,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return self::SUPPORTED_FAMILIES;
    }

    /**
     * @param  list<string>  $families
     * @return array<string, mixed>
     */
    public function run(Node $node, string $mode = 'verify', array $families = []): array
    {
        $selectedFamilies = $families === [] ? self::SUPPORTED_FAMILIES : $families;
        $issues = [];

        foreach ($selectedFamilies as $family) {
            if ($family !== 'node') {
                continue;
            }

            $snapshot = $this->nodesProbe->introspect($node);
            $issues = [
                ...$issues,
                ...array_map(
                    fn (DriftEntry $entry): array => $this->issuePayload($entry, $node),
                    $this->nodesProbe->diff($node, $snapshot),
                ),
            ];
        }

        return [
            'healthy' => $issues === [],
            'mode' => $mode,
            'scope' => [
                'families' => $selectedFamilies,
                'node' => $node->name,
                'self' => false,
                'app' => null,
                'workspace' => null,
            ],
            'summary' => [
                'issues' => count($issues),
                'fixed' => 0,
                'adopted' => 0,
                'skipped' => 0,
                'conflicts' => 0,
            ],
            'issues' => $issues,
            'actions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function issuePayload(DriftEntry $entry, Node $node): array
    {
        return [
            'family' => 'node',
            'node' => $node->name,
            'key' => $entry->key,
            'kind' => $entry->kind->value,
            'summary' => $entry->summary,
            'detail' => $entry->detail,
        ];
    }
}
