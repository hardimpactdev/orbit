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
        return [...new AptRepositoryRules()->rules()];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if (in_array(trim($this->getContent()), ['', '[]'], true)) {
            return [];
        }
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['repository']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return array<string, mixed>|null */
    public function repository(): ?array
    {
        $value = $this->validated('repository');

        return $value === null ? null : ValidatedData::object($value);
    }
}
