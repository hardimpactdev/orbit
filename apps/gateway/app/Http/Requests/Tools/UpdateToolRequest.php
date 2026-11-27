<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateToolRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['desired_version' => ['nullable', 'string', 'max:255'], 'repository' => AptRepositoryRules::name()];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if (in_array(trim($this->getContent()), ['', '[]'], true)) {
            return [];
        }
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['repository', 'desired_version']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function repository(): ?string
    {
        return ValidatedData::nullableString($this->validated('repository'));
    }

    public function desiredVersion(): ?string
    {
        return ValidatedData::nullableString($this->validated('desired_version'));
    }
}
