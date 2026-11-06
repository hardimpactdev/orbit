<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\StartTaskPrepData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StartTaskPrepRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['command' => ['required', 'string', 'max:128'], 'brief' => ['sometimes', 'nullable', 'string', 'max:8000'], 'reference' => ['sometimes', 'nullable', 'string', 'max:80000']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['command', 'brief', 'reference']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): StartTaskPrepData
    {
        return StartTaskPrepData::from($this->validated());
    }
}
