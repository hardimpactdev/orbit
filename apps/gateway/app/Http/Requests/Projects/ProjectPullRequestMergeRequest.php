<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class ProjectPullRequestMergeRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['pull_request' => ['required', 'integer', 'min:1'], 'head_sha' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['pull_request', 'head_sha']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
