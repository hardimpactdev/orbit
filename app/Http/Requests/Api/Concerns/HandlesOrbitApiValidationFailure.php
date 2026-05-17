<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Concerns;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Http\Exceptions\HttpResponseException;

trait HandlesOrbitApiValidationFailure
{
    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        $field = (string) array_key_first($validator->errors()->messages());

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $this->validationMessageFor($field),
                'meta' => [
                    'field' => $field,
                ],
            ],
        ], 422));
    }

    protected function validationMessageFor(string $field): string
    {
        return 'Validation failed.';
    }
}
