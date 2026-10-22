<?php

declare(strict_types=1);

namespace App\Http\Requests\WebSocket;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class AttachWebSocketAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['public' => ['sometimes', 'boolean:strict']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect(trim($this->getContent()) === '[]' ? '' : $this->getContent(), ['public']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function publicRoute(): ?bool
    {
        $public = $this->validated('public');
        assert($public === null || is_bool($public));

        return $public;
    }
}
