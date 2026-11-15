<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\TaskTurnData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StartTaskTurnRequest extends FormRequest
{
    /**
     * The consult role and `model` were options of the Pi turn on a task VM, which is removed (docs/reference/old-paths.md). A turn in
     * a task copy takes its role's model; an older CLI that sends either gets a validation error on that field.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [

            'role' => ['required', 'string', 'in:implementer,reviewer'],
            'prompt' => ['required', 'string', 'max:100000'],
            'thread' => ['sometimes', 'string', 'regex:/\A(?:new|[1-9][0-9]*)\z/D'],
            'model' => ['prohibited'],
            'check' => ['required_if:role,reviewer', 'prohibited_unless:role,reviewer', 'integer:strict', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'role.in' => 'The role is implementer or reviewer. The consult role belonged to the removed task VM turn.',
            'model.prohibited' => 'A turn takes its role\'s model. The model option belonged to the removed task VM turn.',
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $fields = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['role', 'prompt', 'thread', 'model', 'check']);

            return $fields;
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): TaskTurnData
    {
        $task = $this->route('task');
        abort_unless($task instanceof Task, 404);

        return TaskTurnData::from([...$this->validated(), 'subtask' => $task->id]);
    }
}
