<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** Operation mutations take an empty JSON object. */
final class EmptyOperationRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = $this->getContent();

        if (trim($content) === '') {
            return [];
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, []);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['body' => ['The request body must be an empty JSON object.']]);
        }
    }
}
