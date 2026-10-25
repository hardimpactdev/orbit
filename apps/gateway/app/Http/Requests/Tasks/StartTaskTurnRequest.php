<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\TaskTurnData;
use App\Domain\Tasks\TaskAnalysisPlan;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\StrictJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

final class StartTaskTurnRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'subtask' => ['required', 'integer:strict', 'min:1'],
            'role' => ['required', 'string', 'in:implementer,reviewer,consult'],
            'prompt' => ['required', 'string', 'max:100000'],
            'thread' => ['required', 'string', 'regex:/\A(?:new|[1-9][0-9]*)\z/D'],
            'model' => ['sometimes', 'string', 'max:255'],
            'check' => ['required_if:role,reviewer', 'prohibited_unless:role,reviewer', 'integer:strict', 'min:1'],
            'analysis' => ['sometimes', 'array:operation,plan_sha256', 'required_array_keys:operation,plan_sha256'],
            'analysis.operation' => ['required_with:analysis', 'integer:strict', 'min:1'],
            'analysis.plan_sha256' => ['required_with:analysis', 'string', 'regex:'.TaskAnalysisPlan::DigestPattern],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $fields = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['subtask', 'role', 'prompt', 'thread', 'model', 'check', 'analysis']);
            if (array_key_exists('analysis', $fields)) {
                StrictJson::decode($this->getContent(), PHP_INT_MAX);
            }

            return $fields;
        } catch (UnexpectedValueException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): TaskTurnData
    {
        return TaskTurnData::from($this->validated());
    }
}
