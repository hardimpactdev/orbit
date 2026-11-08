<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StoreTaskCommentRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        if ($this->route('task') instanceof Task && $this->route('task')->parent_id === null) {
            return (new StoreTaskGroupCommentRequest)->rules();
        }

        return [
            'type' => ['required', 'string', Rule::in(['assistance_requested', 'resolution'])],
            'body' => ['required', 'string', 'max:100000'],
            'author' => ['required', 'string', 'max:255'],
            'agent_thread_id' => ['nullable', 'integer', 'exists:agent_threads,id'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $fields = $this->route('task') instanceof Task && $this->route('task')->parent_id === null
            ? ['type', 'body', 'author'] : ['type', 'body', 'author', 'agent_thread_id'];
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), $fields);
        } catch (\UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
