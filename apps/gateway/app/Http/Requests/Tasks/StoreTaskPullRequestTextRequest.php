<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskDonePublication;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** The reviewer's pull request text from its task copy, as JSON or as form fields (docs/reference/tasks.md#the-reviewers-pull-request-text). */
final class StoreTaskPullRequestTextRequest extends FormRequest
{
    /** @return array<string, list<string|Closure>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:256', 'not_regex:/[\r\n]/'],
            // The publish step counts UTF-16 code units, as JavaScript does; so does this limit.
            'body' => ['required', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && intdiv(strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2) > TaskDonePublication::ReviewerBodyLimit) {
                    $fail('The body may hold at most '.TaskDonePublication::ReviewerBodyLimit.' UTF-16 code units.');
                }
            }],
        ];
    }
}
