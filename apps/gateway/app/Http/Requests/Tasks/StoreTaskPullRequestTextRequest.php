<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskDonePublication;
use Illuminate\Foundation\Http\FormRequest;

/** The reviewer's pull request text from its task copy, as JSON or as form fields (docs/reference/tasks.md#the-reviewers-pull-request-text). */
final class StoreTaskPullRequestTextRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:256', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:'.TaskDonePublication::ReviewerBodyLimit],
        ];
    }
}
