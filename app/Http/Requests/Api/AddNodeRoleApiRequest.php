<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\HandlesOrbitApiValidationFailure;
use Illuminate\Foundation\Http\FormRequest;

class AddNodeRoleApiRequest extends FormRequest
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

    protected function validationMessageFor(string $field): string
    {
        return match ($field) {
            'settings' => 'Settings must be an object.',
            default => 'Role is required.',
        };
    }
}
