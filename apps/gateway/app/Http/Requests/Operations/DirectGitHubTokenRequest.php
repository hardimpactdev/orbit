<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Validation\Validator;

/** Mint-only peer request. Intent/private/result endpoints keep their own authorization. */
final class DirectGitHubTokenRequest extends OperationPeerRequest
{
    public function authorize(): bool
    {
        $operation = $this->route('operation');
        $caller = $this->peerNodeId();
        if (! $operation instanceof Operation || ($caller !== $operation->node_id && ! ($caller === 209 && $operation->kind === 'tasks.check'))) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node cannot mint for this Operation.', 403);
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
