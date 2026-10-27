<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Domain\SourceControl\GitCommitSha;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class DeployInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['commit' => ['sometimes', 'required', 'string', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = $this->getContent();

        if (trim($content) === '') {
            throw ValidationException::withMessages(['body' => ['The request body must be a JSON object.']]);
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, ['commit']);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['body' => ['The request body must be a JSON object.']]);
        }
    }

    public function commit(): ?string
    {
        $commit = $this->validated('commit');

        return is_string($commit) ? GitCommitSha::validate($commit) : null;
    }
}
