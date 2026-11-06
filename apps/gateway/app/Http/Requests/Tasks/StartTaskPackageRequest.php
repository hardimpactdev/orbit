<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\StartTaskPackageData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StartTaskPackageRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // One pattern per manager, so a package is a registry name with an optional version, never a URL, a path, a Git source or an option.
        $package = match ($this->json('manager')) {
            'composer' => '#\A[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*(:[A-Za-z0-9._~^<>=|*,@-]+)?\z#D',
            default => '#\A(@[a-z0-9~][a-z0-9._~-]*/)?[a-z0-9~][a-z0-9._~-]*(@[A-Za-z0-9._~^<>=|*,-]+)?\z#D',
        };

        return ['manager' => ['required', 'string', 'in:composer,npm'], 'package' => ['required', 'string', 'max:214', 'regex:'.$package]];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['manager', 'package']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): StartTaskPackageData
    {
        return StartTaskPackageData::from($this->validated());
    }
}
