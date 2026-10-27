<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use Illuminate\Foundation\Http\FormRequest;

final class DeploymentSourceChecksRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['commit' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D']];
    }

    public function commit(): string
    {
        $commit = $this->validated('commit');
        assert(is_string($commit));

        return $commit;
    }
}
