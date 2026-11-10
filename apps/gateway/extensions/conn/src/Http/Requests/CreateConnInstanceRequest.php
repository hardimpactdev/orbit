<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Domain\Instances\DefaultInstanceName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

/** The name of a thread's clone. A name that means the Project's default Instance is refused: a thread always starts in a new clone. */
final class CreateConnInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['name']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $project = $this->route('project');
            $name = $validator->getData()['name'] ?? null;
            if ($project instanceof Project && is_string($name) && DefaultInstanceName::resolve($project, $name) === DefaultInstanceName::Name) {
                $validator->errors()->add('name', 'A thread starts in a new clone; this name means the default Instance.');
            }
        }];
    }

    public function name(): string
    {
        return ValidatedData::string($this->validated()['name'] ?? null);
    }
}
