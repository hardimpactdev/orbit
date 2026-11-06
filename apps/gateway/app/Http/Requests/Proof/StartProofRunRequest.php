<?php

declare(strict_types=1);

namespace App\Http\Requests\Proof;

use App\Data\Proof\StartProofRunData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StartProofRunRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['commit' => ['required', 'string', 'regex:/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/D'], 'all' => ['sometimes', 'boolean:strict'], 'report_only' => ['sometimes', 'boolean:strict']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['commit', 'all', 'report_only']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): StartProofRunData
    {
        return StartProofRunData::from($this->validated());
    }
}
