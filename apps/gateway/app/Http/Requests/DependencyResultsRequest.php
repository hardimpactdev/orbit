<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class DependencyResultsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['source' => ['sometimes', 'string', 'regex:/\A[a-f0-9]{64}\z/D'], 'packages_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'findings_page' => ['sometimes', 'integer', 'min:1', 'max:5']];
    }

    public function source(): ?string
    {
        return ValidatedData::nullableString($this->validated('source'));
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if ($this->getContent() !== '' || array_diff(array_keys($this->query->all()), ['source', 'packages_page', 'findings_page']) !== []) {
            throw ValidationException::withMessages(['request' => ['Use only source and pagination query parameters with an empty body.']]);
        }

        return $this->query->all();
    }
}
