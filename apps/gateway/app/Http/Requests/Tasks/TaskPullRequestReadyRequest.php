<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class TaskPullRequestReadyRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['head_sha' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D'], 'mode' => ['required', 'string', 'in:manual,automatic']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['head_sha', 'mode']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
