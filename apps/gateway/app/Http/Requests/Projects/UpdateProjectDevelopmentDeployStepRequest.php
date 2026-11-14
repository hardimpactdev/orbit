<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Domain\Projects\DevelopmentDeployStep;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

final class UpdateProjectDevelopmentDeployStepRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'command' => ['sometimes', 'string'],
            'timeout_seconds' => ['sometimes', 'integer:strict'],
            'required' => ['sometimes', 'boolean:strict'],
            'inputs' => ['sometimes', 'nullable', 'array'],
            'before' => ['sometimes', 'string'],
            'after' => ['sometimes', 'string'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), [
                'command',
                'timeout_seconds',
                'required',
                'inputs',
                'before',
                'after',
            ]);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            $inputs = $validator->getData()['inputs'] ?? null;
            $error = is_array($inputs) && ! $validator->errors()->has('inputs') ? DevelopmentDeployStep::inputsError($inputs) : null;
            if ($error !== null) {
                $validator->errors()->add('inputs', $error);
            }
        });
        $validator->after(function () use ($validator): void {
            $payload = $this->validated();

            if ($payload === []) {
                $validator->errors()->add('body', 'Provide at least one development deploy step update field.');
            }
        });
    }

    public function command(): ?string
    {
        $command = $this->validated('command');

        return is_string($command) ? $command : null;
    }

    public function required(): ?bool
    {
        $required = $this->validated('required');

        return is_bool($required) ? $required : null;
    }

    /** @return list<string>|null null keeps the stored inputs; an empty list, from `null` or `[]`, clears them */
    public function inputs(): ?array
    {
        $payload = $this->validated();

        if (! array_key_exists('inputs', $payload)) {
            return null;
        }

        return is_array($payload['inputs']) ? DevelopmentDeployStep::validInputs($payload['inputs']) : [];
    }

    public function hasCommand(): bool
    {
        return array_key_exists('command', $this->validated());
    }

    public function timeoutSeconds(): ?int
    {
        $timeout = $this->validated('timeout_seconds');

        return is_int($timeout) ? $timeout : null;
    }

    public function hasTimeout(): bool
    {
        return array_key_exists('timeout_seconds', $this->validated());
    }

    public function beforeStep(): ?string
    {
        $before = $this->validated('before');

        return is_string($before) ? $before : null;
    }

    public function afterStep(): ?string
    {
        $after = $this->validated('after');

        return is_string($after) ? $after : null;
    }
}
