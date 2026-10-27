<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SetNodePlatformRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::in(['linux', 'macos', 'ipados'])],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['platform']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function platform(): string
    {
        $validated = $this->validated();

        return is_string($validated['platform'] ?? null) ? $validated['platform'] : '';
    }
}
