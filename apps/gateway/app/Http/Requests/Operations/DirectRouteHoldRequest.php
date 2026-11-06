<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Validation\Validator;

/** The hold call of a run's Route-publish step: only the root Node or the Swamp Node, for the run bound to the Operation. */
final class DirectRouteHoldRequest extends OperationPeerRequest
{
    public function authorize(): bool
    {
        $operation = $this->route('operation');
        if (! $operation instanceof Operation || ! RoutePublicationHolds::holdsPerStep($operation->kind)) {
            throw new ResourceOperationException('operation.route_hold_forbidden', 'This Operation holds no Nodes for a step.', 403);
        }
        $caller = $this->peerNodeId();
        if ($caller !== $operation->node_id && $caller !== SwampNode::id()) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node cannot hold Nodes for this Operation.', 403);
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
