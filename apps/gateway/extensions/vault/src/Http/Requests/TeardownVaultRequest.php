<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Http\Requests;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class TeardownVaultRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'keep_items' => ['sometimes', 'boolean:strict'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        if (in_array(trim($this->getContent()), ['', '[]', '{}'], true)) {
            return [];
        }

        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['keep_items']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function keepItems(): bool
    {
        return ($this->validated()['keep_items'] ?? false) === true;
    }
}
