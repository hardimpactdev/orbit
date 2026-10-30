<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\EnvironmentSyncMaterial;
use App\Domain\Instances\Environment\InstanceServedWebRootsExecution;
use App\Domain\Instances\PhpRuntime\InstancePhpRuntimeExecution;
use App\Domain\Instances\StepLists\InstanceStepListExecution;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Validates an Instance-owned child before its normal Operation admission. */
final readonly class StartInstanceExecutionChildAction
{
    public function __construct(private StartOperationAction $operations) {}

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        if (! OperationKinds::supports('children')) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Instance child execution needs the child Operation kit.', 503);
        }
        $id = ValidatedData::integer($intent['input']['instance_id'] ?? null);
        $candidate = new Operation()->forceFill(['kind' => $intent['kind'], 'target_type' => 'instance', 'target_id' => $id,
            'node_id' => $intent['node_id'], 'input' => $intent['input'], 'parent_operation_id' => $parent->id, 'child_key' => $intent['key']]);
        $allowed = match ($intent['kind']) {
            'instance.sync_environment' => ['instance_id', 'route_domain'],
            'instance.run_steps' => ['instance_id', 'source', 'list', 'configuration_digest'],
            'instance.prepare_vp_environment', 'instance.prepare_php_runtime', 'instance.prepare_served_web_roots' => ['instance_id'],
            default => throw $this->invalid(),
        };
        if (array_diff(array_keys($intent['input']), $allowed) !== []) {
            throw $this->invalid();
        }
        match ($intent['kind']) {
            'instance.sync_environment' => app(EnvironmentSyncMaterial::class)->context($candidate),
            'instance.run_steps' => $this->stepList($parent, $candidate),
            'instance.prepare_served_web_roots' => app(InstanceServedWebRootsExecution::class)->context($candidate),
            'instance.prepare_vp_environment' => app(InstanceVpEnvironmentMaterial::class)->target($candidate),
            default => app(InstancePhpRuntimeExecution::class)->plan($candidate),
        };

        return $this->operations->execute($intent['kind'], 'instance', $id, $intent['node_id'], $intent['input'], $parent->triggered_by, null);
    }

    private function stepList(Operation $parent, Operation $candidate): void
    {
        $member = null;
        $members = $parent->input['members'] ?? [];
        if (! is_array($members) || ! array_is_list($members)) {
            throw $this->invalid();
        }
        foreach ($members as $entry) {
            if (is_array($entry) && ($entry['instance_id'] ?? null) === $candidate->target_id) {
                $member = $entry;
            }
        }
        $phase = $candidate->input['list'] ?? null;
        $digestInput = $parent->kind === 'instance.register' ? $parent->input : ($member ?? $parent->input);
        $digest = $digestInput[($phase === 'setup' ? 'setup' : 'teardown').'_digest'] ?? null;
        if ($parent->target_type !== 'instance' || $candidate->node_id !== $parent->node_id
            || ($member === null && $parent->target_id !== $candidate->target_id)
            || ! in_array($parent->kind, ['instance.create', 'instance.register', 'instance.destroy', 'instance.destroy_member'], true)
            || ! in_array($phase, ['setup', 'teardown'], true)
            || ! is_string($digest) || $digest !== ($candidate->input['configuration_digest'] ?? null)) {
            throw $this->invalid();
        }
        app(InstanceStepListExecution::class)->instance($candidate);
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.child_forbidden', 'The Instance child does not match its owning parent.', 422);
    }
}
