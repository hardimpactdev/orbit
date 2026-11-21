<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskFindingCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

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
                $verdict = $this->input('verdict');
                if ($verdict === 'approve' && $value !== []) {
                    $fail('An approve has no blocking finding: findings must be empty.');
                } elseif ($verdict === 'changes' && $value === []) {
                    $fail('Changes need at least one blocking finding.');
                }
            }],
            'findings.*' => ['required', 'string', Rule::enum(TaskFindingCategory::class)],
        ];
    }

    /** @return list<TaskFindingCategory> the category of each blocking finding, in the order the reviewer posted them */
    public function findings(): array
    {
        /** @var list<string> $findings */
        $findings = $this->validated('findings');

        return array_map(TaskFindingCategory::from(...), $findings);
    }
}
