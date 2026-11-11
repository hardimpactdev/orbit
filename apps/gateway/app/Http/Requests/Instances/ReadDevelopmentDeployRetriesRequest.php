<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use Illuminate\Foundation\Http\FormRequest;

final class ReadDevelopmentDeployRetriesRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'commits' => ['required', 'array', 'min:1', 'max:50'],
            'commits.*' => ['required', 'string', 'distinct', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
        ];
    }

    /** @return list<string> */
    public function commits(): array
    {
        return $this->validated('commits');
    }
}
