<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AddNodeRoleApiRequest extends FormRequest
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
            'role' => ['required', 'string', 'filled', 'max:255'],
            'settings' => ['nullable', 'array'],
        ];
    }

    public function role(): string
    {
        return (string) $this->validated('role');
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->validated('settings', []);

        return is_array($settings) ? $settings : [];
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Role is required.',
                'meta' => [
                    'field' => 'role',
                ],
            ],
        ], 422));
    }
}
