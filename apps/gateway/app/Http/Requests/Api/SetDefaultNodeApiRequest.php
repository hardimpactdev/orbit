<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SetDefaultNodeApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'filled', 'max:255'],
        ];
    }

    public function defaultNodeName(): string
    {
        return (string) $this->validated('name');
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Node name is required.',
                'meta' => [
                    'field' => 'name',
                ],
            ],
        ], 422));
    }
}
