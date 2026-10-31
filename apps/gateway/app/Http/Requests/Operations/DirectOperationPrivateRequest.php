<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Validation\Validator;

final class DirectOperationPrivateRequest extends DirectOperationRequest
{
    public function authorize(): bool
    {
        parent::authorize();
        $operation = $this->route('operation');
        if (! $operation instanceof Operation || $operation->node_id !== $this->peerNodeId()) {
            throw new ResourceOperationException('operation.executor_forbidden', 'Private transport requires the Operation Node.', 403);
        }

        return true;
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['run_id', 'outputs']) !== []) {
                $validator->errors()->add('request', 'Unexpected request fields.');
            }
        }];
    }

    /** Preserve private JSON bytes before global string normalization.
     * @return array<string,mixed>
     */
    public function validationData(): array
    {
        $data = parent::validationData();
        $raw = json_decode($this->getContent(), true);
        if (is_array($raw) && array_key_exists('outputs', $raw)) {
            $data['outputs'] = $raw['outputs'];
        }

        return $data;
    }

    public function rules(): array
    {
        return ['run_id' => ['required', 'uuid'],
            'outputs' => [$this->route()?->getActionMethod() === 'secretOutput' ? 'required' : 'prohibited', 'array', 'min:1', 'max:64'],
        ];
    }
}
