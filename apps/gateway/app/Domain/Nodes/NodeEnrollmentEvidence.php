<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Verifies the immutable child sequence before an enrollment database stage. */
final readonly class NodeEnrollmentEvidence
{
    public function __construct(private OperationChildren $children) {}

    public function before(Operation $parent, string $key): void
    {
        $plan = $parent->input['_node_plan'] ?? null;
        if (! is_array($plan) || ! array_is_list($plan)) {
            throw $this->unproven();
        }
        foreach ($plan as $entry) {
            $step = ValidatedData::object($entry);
            if ($step['key'] === $key) {
                return;
            }
            $child = $this->children->child($parent, ValidatedData::string($step['key']));
            $expected = array_intersect_key($step, array_flip(['key', 'kind', 'node_id', 'input']));
            if (! OperationChildIntent::same(ValidatedData::object($child->child_admission['intent'] ?? []), $expected)
                || ! $child->status->terminal() || (($step['optional'] ?? false) !== true && $child->status !== OperationStatus::Succeeded)) {
                throw $this->unproven();
            }
        }
        throw $this->unproven();
    }

    private function unproven(): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven', 'The enrollment stage requires its preceding recorded child proofs.', 409);
    }
}
