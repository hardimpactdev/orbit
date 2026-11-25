<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use App\Domain\Tools\ToolConvergeIntent;
use Illuminate\Foundation\Http\FormRequest;

final class ConvergeToolsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['tool_ids' => ['sometimes', 'array', 'list', 'min:1', 'max:'.ToolConvergeIntent::MaxBatch],
            'tool_ids.*' => ['required', 'integer', 'min:1', 'distinct']];
    }

    /** @return list<int>|null */
    public function toolIds(): ?array
    {
        /** @var list<int|string>|null $ids */
        $ids = $this->validated('tool_ids');

        return $ids === null ? null : array_map(intval(...), $ids);
    }
}
