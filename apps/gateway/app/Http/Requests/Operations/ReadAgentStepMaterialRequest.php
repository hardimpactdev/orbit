<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ReadAgentStepMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $operation = $this->route('operation');
        $node = $this->getUserResolver()();
        if (! $node instanceof Node || ! $operation instanceof Operation || $operation->node_id !== $node->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The material belongs to another Node.', 403);
        }

        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['step' => ['required', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9_]*\.[a-z0-9][a-z0-9_-]*\z/D'],
            'digest' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D']];
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['step', 'digest']) !== []) {
                $validator->errors()->add('input', 'Only the admitted step and digest are accepted.');
            }
        }];
    }
}
