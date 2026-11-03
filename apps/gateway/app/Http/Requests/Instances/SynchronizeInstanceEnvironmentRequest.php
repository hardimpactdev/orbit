<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SynchronizeInstanceEnvironmentRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // `force` replaces a file whose keys the Node agent has not reported, or that holds keys stored configuration lacks.
        return ['force' => ['sometimes', 'boolean:strict']];
    }

    public function force(): bool
    {
        return $this->validated('force', false) === true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = $this->getContent();

        if (trim($content) === '') {
            throw ValidationException::withMessages(['body' => ['The request body must be a JSON object with at most `force`.']]);
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, ['force']);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['body' => ['The request body must be a JSON object with at most `force`.']]);
        }
    }
}
