<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\DirectOperation;
use App\Domain\Proof\ProofSettings;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Validation\Validator;

/** The seed-hold call of a deployment of the proof default, before its first `seed` phase: only the root Node or the Swamp Node, for the run bound to the Operation. */
final class DirectSeedHoldRequest extends OperationPeerRequest
{
    public function authorize(): bool
    {
        $operation = $this->route('operation');
        if (! $operation instanceof Operation || $operation->kind !== 'instance.deploy' || $operation->target_type !== 'instance'
            || ! Instance::query()->whereKey($operation->target_id)->where('project_id', app(ProofSettings::class)->projectId())
                ->where('name', DefaultInstanceName::Name)->exists()) {
            throw new ResourceOperationException('operation.seed_hold_forbidden', 'This Operation holds no seed.', 403);
        }
        $caller = $this->peerNodeId();
        if ($caller !== $operation->node_id && $caller !== SwampNode::id()) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node cannot hold the seed for this Operation.', 403);
        }

        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['run_id' => ['required', 'uuid']];
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['run_id']) !== []) {
                $validator->errors()->add('request', 'Unexpected request fields.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        $operation = $this->route('operation');
        if ($operation instanceof Operation) {
            app(DirectOperation::class)->assertRun($operation, ValidatedData::string($this->validated('run_id')));
        }
    }
}
