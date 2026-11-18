<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use Illuminate\Validation\Validator;

final class SignDirectExecutorOrderRequest extends OperationPeerRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['run_id' => ['required', 'uuid'], 'phase' => ['required', 'in:power,resume,boot,removal']];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['run_id', 'phase']) !== []) {
                $validator->errors()->add('phase', 'The sign request may name only its run and phase.');
            }
        }];
    }
}
