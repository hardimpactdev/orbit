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
        return ['commit' => ['required', 'string', 'regex:/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/D'],
            'base' => ['sometimes', 'required', 'string', 'regex:/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/D', 'prohibited_if:all,true'],
            'all' => ['sometimes', 'boolean:strict'], 'report_only' => ['sometimes', 'boolean:strict']];
    }

    /**
     * `keep_failed` is refused by name, not as an unknown key: it kept the VM of a failed run, and a proof now runs only in copies,
     * which every run removes. An older CLI sends it for `proof:run --keep-failed`. The field is in no rule, so the API contract does
     * not list it.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        try {
            $data = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['commit', 'base', 'all', 'report_only', 'keep_failed']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
        if (array_key_exists('keep_failed', $data)) {
            throw ValidationException::withMessages(['keep_failed' => ['keep_failed is removed: a proof runs in copies, and every run removes its copy. Run proof:run without --keep-failed.']]);
        }

        return $data;
    }

    public function payload(): StartProofRunData
    {
        return StartProofRunData::from($this->validated());
    }
}
