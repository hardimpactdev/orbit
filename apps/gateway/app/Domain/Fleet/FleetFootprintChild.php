<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Read only the shared child relation. Swamp cannot supply footprint proof in a parent run body. */
final readonly class FleetFootprintChild
{
    public function succeeded(Operation $parent): Operation
    {
        $child = Operation::query()->where('parent_operation_id', $parent->id)->where('child_key', 'footprint')
            ->where('kind', 'node.converge')->where('node_id', $parent->node_id)->first();
        $expected = $parent->kind === 'fleet.recipe'
            ? ['force_artifacts' => true, 'first_visit' => false]
            : ['force_artifacts' => ($parent->input['repairs'] ?? []) !== [], 'first_visit' => $parent->input['first_visit']];
        if ($child === null || $child->status !== OperationStatus::Succeeded
            || count($child->input) !== count($expected)
            || array_any($expected, static fn ($value, $key): bool => ($child->input[$key] ?? null) !== $value)) {
            throw new ResourceOperationException('operation.completion_unproven', 'A successful footprint child with the admitted fleet inputs is required.', 409);
        }

        return $child;
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    public function caddySkip(array $result): ?array
    {
        $skipped = $result['skipped'] ?? [];
        if (! is_array($skipped) || ! array_is_list($skipped)) {
            throw new ResourceOperationException('operation.completion_unproven', 'Footprint skips must be a declared list.', 409);
        }
        foreach ($skipped as $entry) {
            $entry = ValidatedData::object($entry);
            if (($entry['kind'] ?? null) === 'route.caddy_converge') {
                return $entry;
            }
        }

        return null;
    }
}
