<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use Illuminate\Foundation\Http\FormRequest;

final class DeploymentSourceCommitsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['base' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D']];
    }

    public function base(): string
    {
        $base = $this->validated('base');
        assert(is_string($base));

        return $base;
    }
}
