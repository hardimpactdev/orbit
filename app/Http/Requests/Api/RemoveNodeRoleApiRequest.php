<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RemoveNodeRoleApiRequest extends FormRequest
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
            'force' => ['nullable', 'boolean'],
            'purge_data' => ['nullable', 'boolean'],
        ];
    }

    public function force(): bool
    {
        return (bool) $this->boolean('force');
    }

    public function purgeData(): bool
    {
        return (bool) $this->boolean('purge_data');
    }
}
