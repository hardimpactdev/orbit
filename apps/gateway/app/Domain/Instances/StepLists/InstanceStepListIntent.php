<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/** Current target and immutable configuration validation; no execution or receipts. */
final readonly class InstanceStepListIntent
{
    public function __construct(private StartInstanceStepListAction $admission) {}

    /** @return array<string, mixed> */
    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $phase = is_string($input['list'] ?? null) ? LifecyclePhase::tryFrom($input['list']) : null;
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($operation->target_id);
        if ($operation->kind !== 'instance.run_steps' || $operation->target_type !== 'instance'
            || $operation->node_id !== $instance->node_id || ($input['instance_id'] ?? null) !== $instance->id
            || ($input['source'] ?? null) !== 'project' || $phase === null || $instance->placedOnAppProd()
            || ! str_starts_with($instance->checkout_path, '/') || str_contains($instance->checkout_path, "\0")
            || preg_match('/\A[a-z_][a-z0-9_-]{0,63}\z/D', $instance->node->user) !== 1 || $instance->node->user === 'root'
            || ($input['configuration_digest'] ?? null) !== $this->admission->configurationDigest($instance->project, $phase)) {
            throw $this->refused();
        }
        $admitted = $this->admission->appStepAdmission($instance, $phase);
        foreach ($admitted as $field => $value) {
            if (($input[$field] ?? null) !== $value) {
                throw $this->refused();
            }
        }

        return ['instance_id' => $instance->id, 'list' => $phase->value, ...$admitted];
    }

    private function refused(): ResourceOperationException
    {
        return new ResourceOperationException('instance.step_not_admitted', 'The Project list does not match its admitted target and configuration.', 409);
    }
}
