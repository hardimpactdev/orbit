<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Read only the shared child relation. Swamp cannot supply footprint proof in a parent run body. */
final readonly class FleetFootprintChild
{
    public function __construct(private OperationChildren $children) {}

    public function succeeded(Operation $parent): Operation
    {
        $child = $this->children->requireSucceeded($parent, 'footprint');
        if ($child->kind !== 'node.converge' || $child->node_id !== $parent->node_id) {
            throw new ResourceOperationException('operation.completion_unproven', 'The proven footprint child has another kind or target.', 409);
        }

        return $child;
    }

    public function cliReady(Operation $operation): bool
    {
        $steps = ValidatedData::object($operation->state['steps'] ?? []);
        foreach (['cli-install' => 'cli.install', 'self-update' => 'cli.self-update'] as $step => $command) {
            $proof = $steps[$step] ?? null;
            $proven = false;
            foreach (ValidatedData::object($operation->state['executor_runs'] ?? []) as $run) {
                if (is_array($run) && ($run['transport'] ?? null) === 'agent' && ($run['name'] ?? null) === $command
                    && isset($run['result']) && $run['result'] === $proof && is_array($proof) && ! isset($proof['error_code'])) {
                    $proven = true;
                }
            }
            if (! $proven || ($step === 'self-update' && ! in_array(is_array($proof) ? ($proof['outcome'] ?? null) : null, ['updated', 'unchanged'], true))) {
                return false;
            }
        }

        return true;
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
