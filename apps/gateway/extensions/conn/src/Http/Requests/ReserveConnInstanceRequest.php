<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use LogicException;
use Orbit\Extensions\Conn\Domain\ConnThreadProjects;
use UnexpectedValueException;

/** A reservation of a clone of the Project's ready default (docs/reference/conn.md#reserve-a-clone): an empty JSON object, after the Project checks of a create. */
final class ReserveConnInstanceRequest extends FormRequest
{
    private ?Instance $default = null;

    public function authorize(ConnThreadProjects $projects): bool
    {
        $caller = $this->user();
        $project = $this->route('project');
        assert($caller instanceof Node && $project instanceof Project, description: 'An authenticated Node reserves a clone of a bound Project.');
        $this->default = $projects->default($caller, $project);

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent() === '' ? '{}' : $this->getContent(), []);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** The Project's ready default that the clone copies, as authorize() found it. */
    public function default(): Instance
    {
        return $this->default ?? throw new LogicException('The request was not authorized.');
    }
}
