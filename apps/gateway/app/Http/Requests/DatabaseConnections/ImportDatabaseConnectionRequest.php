<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseConnections;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** database:import: the absolute path of the dump on the server's Node; DatabaseImport::path() holds the path rule. */
final class ImportDatabaseConnectionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['file' => ['required', 'string', 'max:1024']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['file']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function dumpFile(): string
    {
        $validated = $this->validated();

        return is_string($validated['file'] ?? null) ? $validated['file'] : '';
    }
}
