<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class AddAptRepositoryRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer', 'min:1', 'exists:nodes,id', $this->strictInteger(...)],
            'name' => ['required', ...array_slice(AptRepositoryRules::name(), 1)],
            ...AptRepositoryRules::definition('definition'),
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['node_id', 'name', 'definition']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function nodeId(): int
    {
        return $this->integer('node_id');
    }

    public function repositoryName(): string
    {
        return $this->string('name')->toString();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ValidatedData::object($this->validated('definition'));
    }

    private function strictInteger(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value)) {
            $fail("The {$attribute} field must be an integer.");
        }
    }
}
