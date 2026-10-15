<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Http\Requests;

final class WriteDocumentRequest extends DocumentRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:1'],
            'content_text' => ['sometimes', 'string'],
            'content_base64' => ['sometimes', 'string'],
            'media_type' => ['sometimes', 'required', 'string', 'max:127'],
        ];
    }
}
