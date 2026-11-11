<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Domain\Instances\InstanceName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use LogicException;
use Orbit\Extensions\Conn\Domain\ConnInstanceNames;
use Orbit\Extensions\Conn\Domain\ConnThreadProjects;
use UnexpectedValueException;

/** The name of a thread's clone. A name that means the Project's default Instance is refused: a thread always starts in a new clone. */
final class CreateConnInstanceRequest extends FormRequest
{
    private ?Instance $default = null;

    /** Node access to the default's Node is checked before validation, so a caller without it learns nothing about the Project. */
    public function authorize(ConnThreadProjects $projects): bool
    {
        $caller = $this->user();
        $project = $this->route('project');
        assert($caller instanceof Node && $project instanceof Project, description: 'An authenticated Node creates a clone of a bound Project.');
        $this->default = $projects->default($caller, $project);

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => InstanceName::rules(),
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
            if ($project instanceof Project && is_string($name) && app(ConnInstanceNames::class)->isDefault($project, $name)) {
                $validator->errors()->add('name', 'A thread starts in a new clone; this name means the default Instance.');
            }
        }];
    }

    /** The Project's ready default that the clone copies, as authorize() found it. */
    public function default(): Instance
    {
        return $this->default ?? throw new LogicException('The request was not authorized.');
    }

    public function name(): string
    {
        return ValidatedData::string($this->validated()['name'] ?? null);
    }
}
