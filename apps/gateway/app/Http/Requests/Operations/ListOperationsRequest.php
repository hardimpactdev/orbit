<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Actions\Operations\ListOperationsAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The list filters and page. `kind` and `target_type` are checked by form, so a kind without operations lists nothing. */
final class ListOperationsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(OperationStatus::class)],
            'kind' => ['sometimes', 'string', 'max:64', 'regex:'.OperationKinds::NamePattern],
            'target_type' => ['sometimes', 'string', 'max:32', 'regex:'.OperationKinds::TargetTypePattern],
            'target_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ListOperationsAction::MaxLimit],
            'cursor' => ['sometimes', 'string', 'max:20', 'regex:/\A[1-9][0-9]*\z/'],
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

    public function limit(): int
    {
        return $this->integer('limit', ListOperationsAction::DefaultLimit);
    }

    /** The ID the next page starts below. */
    public function cursor(): ?int
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) ? (int) $cursor : null;
    }
}
