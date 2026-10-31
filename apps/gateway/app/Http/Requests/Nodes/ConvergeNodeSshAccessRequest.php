<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** Target identity and the public key are fixed by tracked policy. */
final class ConvergeNodeSshAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), []);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
