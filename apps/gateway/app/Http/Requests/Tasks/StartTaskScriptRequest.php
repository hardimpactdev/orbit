<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\StartTaskScriptData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StartTaskScriptRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['subtask' => ['required', 'integer:strict', 'min:1'],
            'script' => ['required', 'string', 'max:512', 'regex:~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*\z~D', 'not_regex:~(?:\A|/)\.{1,2}(?:/|\z)~D'],
            'args' => ['present', 'array', 'list', 'max:32'], 'args.*' => ['present', 'string', 'max:4096', 'not_regex:/[\x00]/']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['subtask', 'script', 'args']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): StartTaskScriptData
    {
        return StartTaskScriptData::from($this->validated());
    }
}
