<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** The Gateway captures source and board state; the caller only says whether to open the update group. */
final class InspectProjectMaintenanceRequest extends FormRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['open' => ['sometimes', 'boolean']];
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        if (trim($this->getContent()) === '[]') {
            return [];
        }
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['open']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
