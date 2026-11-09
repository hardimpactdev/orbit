<?php

declare(strict_types=1);

namespace App\Http\Requests\Proof;

use App\Data\Proof\StartProofVerifyData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StartProofVerifyRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['command' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]*(?::[a-z][a-z0-9_-]*)+\z/D'], 'group' => ['sometimes', 'integer', 'min:1']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['command', 'group']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): StartProofVerifyData
    {
        return StartProofVerifyData::from($this->validated());
    }
}
