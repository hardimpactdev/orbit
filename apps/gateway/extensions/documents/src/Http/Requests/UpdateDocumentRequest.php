<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Http\Requests;

final class UpdateDocumentRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
