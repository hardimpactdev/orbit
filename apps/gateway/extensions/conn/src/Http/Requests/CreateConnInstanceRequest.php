<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Domain\Instances\InstanceBranchSelection;
use App\Domain\Instances\InstanceName;
use App\Domain\SourceControl\GitBranchName;
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

    /** The ordered Project start checks run before validating the proposed name. */
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
            'branch' => ['sometimes', 'required', 'array:mode,from,name'],
            'branch.mode' => ['required_with:branch', 'string', 'in:new,existing'],

        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['name', 'branch']);
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
            $data = $validator->getData();
            if (array_key_exists('branch', $data) && is_array($data['branch'])) {
                $branch = $data['branch'];
                $mode = $branch['mode'] ?? null;
                if (in_array($mode, ['new', 'existing'], true)) {
                    $required = $mode === 'new' ? 'from' : 'name';
                    $forbidden = $mode === 'new' ? 'name' : 'from';
                    if (! is_string($branch[$required] ?? null) || ! GitBranchName::isValid($branch[$required])) {
                        $validator->errors()->add('branch.'.$required, 'A valid Git branch name is required.');
                    }
                    if (array_key_exists($forbidden, $branch)) {
                        $validator->errors()->add('branch.'.$forbidden, 'This field is not allowed in this mode.');
                    }
                    if ($mode === 'new' && is_string($name) && ! GitBranchName::isValid($name)) {
                        $validator->errors()->add('name', 'The derived Git branch name is invalid.');
                    }
                }
            }
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

    public function branchSelection(): ?InstanceBranchSelection
    {
        $branch = $this->validated()['branch'] ?? null;
        if ($branch === null) {
            return null;
        }
        $branch = ValidatedData::object($branch);
        $mode = ValidatedData::string($branch['mode']);

        return new InstanceBranchSelection($mode, $mode === 'new' ? $this->name() : ValidatedData::string($branch['name']),
            $mode === 'new' ? ValidatedData::string($branch['from']) : null);
    }

    public function name(): string
    {
        return ValidatedData::string($this->validated()['name'] ?? null);
    }
}
