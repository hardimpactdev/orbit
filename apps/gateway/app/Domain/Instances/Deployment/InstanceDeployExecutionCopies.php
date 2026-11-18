<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\NativeRunCancellation;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Models\Operation;

/**
 * The vault copies that one `instance.deploy` names after its own Operation id alone: its read credential, a container default's
 * Process environment files and one command per admitted step. They leave the vault when the deploy has ended
 * (docs/reference/deployments.md#execution-copies).
 */
final readonly class InstanceDeployExecutionCopies
{
    public const string Vault = 'orbit-control-inputs';

    public function __construct(private SwampScheduleWorkflows $vault) {}

    /**
     * Whether the deploy's run can no longer read its copies. A timed-out deploy's run may still be going, or be reopened when it beat the
     * deadline. One that holds its Instance keeps its copies until the reader settles it, with Swamp's terminal or after the hold limit. A
     * checkout default's deploy holds nothing and records no settlement: it keeps them until Swamp confirmed the cancel, or until the
     * reconciler stops forwarding that cancel and the run counts as lost.
     */
    public static function ended(Operation $deploy): bool
    {
        if (($deploy->kind !== 'instance.deploy' && ! ($deploy->kind === 'instance.rollback' && isset($deploy->input['production_release']))) || ! $deploy->status->terminal() || ExpireOperationAction::awaitsSettlement($deploy)) {
            return false;
        }

        return ! ExpireOperationAction::expiredUnsettled($deploy) || isset($deploy->state['native_cancel_confirmed_at'])
            || ($deploy->finished_at !== null && $deploy->finished_at->isBefore(now()->subMinutes(NativeRunCancellation::ForwardMinutes)));
    }

    /** @return list<string> */
    public static function keys(Operation $deploy): array
    {
        if (isset($deploy->input['production_release'])) {
            return ['production-release-operation-'.$deploy->id];
        }
        $deployment = $deploy->input['deployment'] ?? null;
        $deployment = is_array($deployment) ? $deployment : [];
        $variant = $deployment['variant'] ?? null;
        if ($variant === 'rollback') {
            return [];
        }
        $prefix = ($variant === 'production' ? 'instance' : 'development').'-deploy-operation-'.$deploy->id.'-';
        $keys = $variant === 'production' ? [$prefix.'credential'] : [$prefix.'credential', $prefix.'processes'];
        $steps = $deployment['steps'] ?? [];
        // The grants bound a step index to 0-31.
        foreach (array_keys(is_array($steps) && array_is_list($steps) ? array_slice($steps, 0, 32) : []) as $index) {
            $keys[] = $prefix.'step-'.$index;
        }

        return $keys;
    }

    /** Deletes the copies of these deploys over one connection; a key that is gone is done. */
    public function remove(Operation ...$deploys): void
    {
        $this->vault->deleteSecrets(self::Vault, array_merge(...array_map(self::keys(...), array_values($deploys))));
    }
}
