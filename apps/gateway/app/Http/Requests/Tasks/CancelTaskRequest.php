<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** `task:cancel`. `discard: true` lets the cancel destroy a task copy that holds work origin lacks, or that cannot be read. */
final class CancelTaskRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['discard' => ['sometimes', 'boolean:strict']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = trim($this->getContent());

        if ($content === '' || $content === '[]') {
            return [];
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($content, ['discard']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function discard(): bool
    {
        return $this->validated('discard', false) === true;
    }
}
