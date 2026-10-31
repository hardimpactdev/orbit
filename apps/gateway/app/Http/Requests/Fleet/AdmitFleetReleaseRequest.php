<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

final class AdmitFleetReleaseRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['rollout_id' => ['sometimes', 'integer', 'min:1'],
            'node_ids' => ['sometimes', 'array', 'min:1', 'max:1000'],
            'node_ids.*' => ['integer', 'distinct', 'exists:nodes,id']];
    }
}
