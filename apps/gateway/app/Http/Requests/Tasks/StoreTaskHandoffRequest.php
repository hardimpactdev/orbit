<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** The review receipt that a reviewer turn posts from its task copy (docs/reference/tasks.md#the-review-receipt). */
final class StoreTaskHandoffRequest extends FormRequest
{
    /** @return array<string, list<string|Closure>> */
    public function rules(): array
    {
        return [
            'verdict' => ['required', 'string', 'in:approve,changes'],
            // `approve` has no blocking finding; `changes` has at least one.
            'findings' => ['required', 'integer', 'min:0', 'max:100', function (string $attribute, mixed $value, Closure $fail): void {
                $verdict = $this->input('verdict');
                $count = filter_var($value, FILTER_VALIDATE_INT);
                if ($verdict === 'approve' && $count !== 0) {
                    $fail('An approve has no blocking finding: findings must be 0.');
                } elseif ($verdict === 'changes' && (! is_int($count) || $count < 1)) {
                    $fail('Changes need at least one blocking finding.');
                }
            }],
        ];
    }
}
