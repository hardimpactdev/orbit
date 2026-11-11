<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Orbit\Extensions\Conn\Domain\ConnThreadProjects;

final class ListConnProjectBranchesRequest extends FormRequest
{
    public function authorize(ConnThreadProjects $projects): bool
    {
        $caller = $this->user();
        $project = $this->route('project');
        assert($caller instanceof Node && $project instanceof Project);
        $projects->default($caller, $project);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['query' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\A[^\x00-\x1F\x7F]*\z/u']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function nameQuery(): string
    {
        $query = $this->validated('query');

        return is_string($query) ? $query : '';
    }
}
