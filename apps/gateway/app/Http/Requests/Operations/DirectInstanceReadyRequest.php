<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Validation\Validator;

/** The ready mark of a container clone's create run (docs/reference/operations.md#ready-mark-of-a-clone-create): only the root Node or the Swamp Node, for the run bound to the Operation. */
final class DirectInstanceReadyRequest extends OperationPeerRequest
{
    public function authorize(): bool
    {
        $operation = $this->route('operation');
        if (! $operation instanceof Operation || ! self::marksReady($operation)) {
            throw new ResourceOperationException('operation.ready_forbidden', 'This Operation has no ready mark.', 403);
        }
        $caller = $this->peerNodeId();
        if ($caller !== $operation->node_id && $caller !== SwampNode::id()) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node cannot mark this Operation ready.', 403);
        }

        return true;
    }

    /** Only the create of a container clone that is not active yet has a ready mark. */
    private static function marksReady(Operation $operation): bool
    {
        $provisioning = $operation->input['direct_provisioning'] ?? null;
        $container = is_array($provisioning) ? ($provisioning['container'] ?? null) : null;

        return $operation->kind === 'instance.create' && is_array($container) && is_array($container['clone'] ?? null)
            && ($provisioning['already_active'] ?? false) !== true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['run_id' => ['required', 'uuid'], 't3' => ['required', 'array']];
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['run_id', 't3']) !== []) {
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

    public function runId(): string
    {
        return ValidatedData::string($this->validated('run_id'));
    }

    /** @return array<string, mixed> */
    public function t3(): array
    {
        return ValidatedData::object($this->validated('t3'));
    }
}
