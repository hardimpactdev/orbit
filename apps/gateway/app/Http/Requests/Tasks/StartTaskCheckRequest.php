<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\StartTaskCheckData;
use App\Domain\Tasks\TaskCheckKind;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StartTaskCheckRequest extends FormRequest
{
    /** @return array<string, list<string|Enum>> */
    public function rules(): array
    {
        return ['subtask' => ['required', 'integer:strict', 'min:1'], 'phase' => ['required', 'string', Rule::enum(TaskCheckKind::class)]];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['subtask', 'phase']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): StartTaskCheckData
    {
        return new StartTaskCheckData($this->integer('subtask'), TaskCheckKind::from($this->string('phase')->toString()));
    }
}
