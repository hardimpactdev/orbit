<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
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
        parse_str($this->server->getString('QUERY_STRING'), $query);

        return array_key_exists('query', $query) ? ['query' => $query['query']] : [];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [static function (Validator $validator): void {
            $query = $validator->getData()['query'] ?? null;
            if (is_string($query) && trim($query) !== $query) {
                $validator->errors()->add('query', 'Leading or trailing whitespace is not allowed.');
            }
        }];
    }

    public function nameQuery(): string
    {
        $query = $this->validated('query');

        return is_string($query) ? $query : '';
    }
}
