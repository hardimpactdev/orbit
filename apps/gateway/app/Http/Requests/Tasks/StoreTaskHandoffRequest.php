<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskFindingCategory;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** The review receipt that a reviewer turn posts from its task copy (docs/reference/tasks.md#the-review-receipt). */
final class StoreTaskHandoffRequest extends FormRequest
{
    /** @return array<string, list<string|Closure|Enum>> */
    public function rules(): array
    {
        return [
            'verdict' => ['required', 'string', 'in:approve,changes'],
            // A JSON list of categories, one for each blocking finding: an integer count is refused. `approve` has none; `changes` has
            // at least one.
            'findings' => ['bail', 'present', 'array', 'list', 'max:100', function (string $attribute, mixed $value, Closure $fail): void {
                $verdict = $this->validationData()['verdict'] ?? null;
                if ($verdict === 'approve' && $value !== []) {
                    $fail('An approve has no blocking finding: findings must be empty.');
                } elseif ($verdict === 'changes' && $value === []) {
                    $fail('Changes need at least one blocking finding.');
                }
            }],
            'findings.*' => ['required', 'string', Rule::enum(TaskFindingCategory::class)],
        ];
    }

    /**
     * The receipt as the JSON body holds it, and nothing else: no form field, no query parameter and no other key. Values are not trimmed,
     * and a JSON object stays an object, so `{}` or `{"0": "race"}` is no list of findings.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        try {
            app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['verdict', 'findings']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        return get_object_vars((object) json_decode($this->getContent()));
    }

    public function verdict(): string
    {
        return ValidatedData::string($this->validated('verdict'));
    }

    /** @return list<TaskFindingCategory> the category of each blocking finding, in the order the reviewer posted them */
    public function findings(): array
    {
        return array_map(TaskFindingCategory::from(...), ValidatedData::stringList($this->validated('findings')));
    }
}
