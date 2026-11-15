<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Operations\ExpireOperationAction;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Models\Operation;

/**
 * The vault copies that one `instance.deploy` names after its own Operation id: its read credential, its Process environment files
 * and one command per admitted step. They leave the vault when the deploy has ended (docs/reference/deployments.md#execution-copies).
 */
final readonly class InstanceDeployExecutionCopies
{
    public const string Vault = 'orbit-control-inputs';

    public function __construct(private SwampScheduleWorkflows $vault) {}

    /**
     * Whether the deploy's run can no longer read its copies. A timed-out deploy whose run Swamp has not yet confirmed ended may still be
     * running, or may be reopened when its run beat the deadline; it keeps its copies until that settles.
     */
    public static function ended(Operation $deploy): bool
    {
        return $deploy->kind === 'instance.deploy' && $deploy->status->terminal() && ! ExpireOperationAction::expiredUnsettled($deploy);
    }

    /** @return list<string> */
    public static function keys(Operation $deploy): array
    {
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
