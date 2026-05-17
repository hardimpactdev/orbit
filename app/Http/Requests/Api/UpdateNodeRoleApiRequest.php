<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\HandlesOrbitApiValidationFailure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNodeRoleApiRequest extends FormRequest
{
    use HandlesOrbitApiValidationFailure;

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
            'settings' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->validated('settings', []);

        return is_array($settings) ? $settings : [];
    }

    protected function validationFailureFields(): array
    {
        return ['settings'];
    }

    protected function validationMessageFor(string $field): string
    {
        return match ($field) {
            'settings' => 'Settings must be an object.',
            default => 'Validation failed.',
        };
    }
}
