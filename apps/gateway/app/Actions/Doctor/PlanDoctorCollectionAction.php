<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Domain\Doctor\DoctorFamily;
use App\Domain\Shared\StoredInteger;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Node;

/** Select stored resources without observing Nodes or dispatching executors. */
final readonly class PlanDoctorCollectionAction
{
    public function __construct(private RunDoctorAction $doctor, private TaskExtensionState $tasks) {}

    /**
     * @return array{collection: string, enabled: bool, nodes: list<int>, probes: list<array{node_id: int, family: string, kind: string, ids: list<int|string>}>}
     */
    public function execute(string $collection): array
    {
        $enabled = $this->tasks->enabled();
        if (! $enabled) {
            return ['collection' => $collection, 'enabled' => false, 'nodes' => [], 'probes' => []];
        }
        $nodes = Node::query()->orderBy('id')->get();
        $probes = [];
        foreach ($nodes as $node) {
            foreach (DoctorFamily::cases() as $family) {
                foreach ($this->doctor->probe($family)->resources($node) as $resource) {
                    $probes[] = ['node_id' => $node->id, 'family' => $family->value,
                        'kind' => $resource->kind->name, 'ids' => $resource->ids];
                }
            }
        }

        return ['collection' => $collection, 'enabled' => $enabled,
            'nodes' => StoredInteger::listFrom($nodes->modelKeys()), 'probes' => $probes];
    }
}
