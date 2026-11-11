<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use LogicException;
use Orbit\Extensions\Conn\Domain\ConnThreadProjects;

final class CheckConnInstanceNameRequest extends FormRequest
{
    private ?Instance $default = null;

    /** Match create's access and ready-default checks before inspecting the name. */
    public function authorize(ConnThreadProjects $projects): bool
    {
        $caller = $this->user();
        $project = $this->route('project');
        assert($caller instanceof Node && $project instanceof Project);
        $this->default = $projects->default($caller, $project);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['present', 'string']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        // Preserve empty and padded candidates that global input middleware would change.
        parse_str($this->server->getString('QUERY_STRING'), $query);
        if (array_diff(array_keys($query), ['name']) !== []) {
            throw ValidationException::withMessages(['query' => ['Only the name query field is allowed.']]);
        }

        return array_key_exists('name', $query) ? ['name' => $query['name']] : [];
    }

    public function default(): Instance
    {
        return $this->default ?? throw new LogicException('The request was not authorized.');
    }

    public function name(): string
    {
        return ValidatedData::string($this->validated()['name'] ?? null);
    }
}
