<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** The Gateway captures source and board state; the caller supplies no overrides. */
final class ProjectMaintenanceRequest extends FormRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        if (trim($this->getContent()) === '[]') {
            return [];
        }
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), []);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
