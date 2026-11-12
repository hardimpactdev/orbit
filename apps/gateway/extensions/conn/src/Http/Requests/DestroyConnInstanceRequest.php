<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class DestroyConnInstanceRequest extends FormRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['acknowledge_unsaved' => ['required', 'boolean:strict']];
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['acknowledge_unsaved']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function acknowledgeUnsaved(): bool
    {
        return $this->validated('acknowledge_unsaved') === true;
    }
}
