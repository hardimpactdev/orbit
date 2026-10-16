<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Operations\OperationKinds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The list filters. `kind` and `target_type` are checked by form, so a kind without operations lists nothing. */
final class ListOperationsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['queued', 'running', 'succeeded', 'failed', 'cancelled'])],
            'kind' => ['sometimes', 'string', 'max:64', 'regex:'.OperationKinds::NamePattern],
            'target_type' => ['sometimes', 'string', 'max:32', 'regex:'.OperationKinds::TargetTypePattern],
            'target_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array{status?: string, kind?: string, target_type?: string, target_id?: int} */
    public function filters(): array
    {
        $filters = [];

        foreach (['status', 'kind', 'target_type'] as $key) {
            $value = $this->validated($key);

            if (is_string($value)) {
                $filters[$key] = $value;
            }
        }

        if ($this->validated('target_id') !== null) {
            $filters['target_id'] = $this->integer('target_id');
        }

        return $filters;
    }
}
